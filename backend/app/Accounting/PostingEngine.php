<?php

namespace App\Accounting;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\DraftJournalRequest;
use App\Accounting\DTOs\DraftJournalResult;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\PostingResult;
use App\Accounting\DTOs\ReversalRequest;
use App\Accounting\Enums\EligibilityResult;
use App\Accounting\Enums\ReasonCode;
use App\Accounting\Enums\StateDimension;
use App\Accounting\Services\AccountMappingService;
use App\Accounting\Services\AccountRoleResolver;
use App\Accounting\Services\ActionEligibilityEvaluator;
use App\Accounting\Services\ReversalCorrectionEngine;
use App\Accounting\Services\StateTransitionService;
use App\Models\AccountingOutboxEvent;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\PostingAuditLog;
use App\Models\User;
use App\Services\ApprovalPolicyService;
use App\Services\CorrectionRequest;
use App\Services\FiscalPeriodValidator;
use App\Services\LockDateValidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class PostingEngine implements PostingEngineInterface
{
    public function __construct(
        private readonly AccountMappingService $accountMapper,
        private readonly AccountRoleResolver $roleResolver,
        private readonly LockDateValidationService $lockDateValidator,
        private readonly FiscalPeriodValidator $fiscalValidator,
        private readonly ApprovalPolicyService $approvalPolicy,
        private readonly ActionEligibilityEvaluator $eligibilityEvaluator,
        private readonly StateTransitionService $transitionService,
        private readonly ReversalCorrectionEngine $reversalEngine,
    ) {}

    /**
     * Post a business document to the ledger.
     */
    public function post(PostingRequest $request): PostingResult
    {
        // ── 0. Idempotency guard ─────────────────────────────
        // ✅ IDEMPOTENT
        if ($request->documentId !== null) {
            $existingLink = JournalEntryLink::where('source_document_type', $request->documentType)
                ->where('source_document_id', $request->documentId)
                ->where('relation_type', 'post')
                ->first();

            if ($existingLink) {
                Log::info('PostingEngine::post — duplicate posting prevented', [
                    'document_type' => $request->documentType,
                    'document_id' => $request->documentId,
                    'entry_id' => $existingLink->journal_entry_id,
                    'request_id' => $request->requestId,
                    'correlation_id' => $request->correlationId,
                ]);

                return PostingResult::success(
                    JournalEntry::find($existingLink->journal_entry_id),
                    null,
                );
            }
        }

        // ── 1. Pre-flight validation ──────────────────────────
        try {
            $this->validateRequest($request);
        } catch (\Exception $e) {
            return PostingResult::failure($e->getMessage());
        }

        // ── 2. Resolve accounts & build line arrays ───────────
        try {
            $resolvedLines = $this->resolveLines($request->lines, $request->documentType);
        } catch (\Exception $e) {
            return PostingResult::failure('Account resolution failed: '.$e->getMessage());
        }

        // ── 3. Balance check ──────────────────────────────────────────
        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($resolvedLines as $line) {
            $totalDebit = bcadd($totalDebit, (string) $line['debit'], 2);
            $totalCredit = bcadd($totalCredit, (string) $line['credit'], 2);
        }

        $difference = bcsub($totalDebit, $totalCredit, 2);

        if (bccomp($difference, '0.01', 2) === 1 || bccomp($difference, '-0.01', 2) === -1) {
            return PostingResult::failure(
                "Journal entry is unbalanced. Debit: {$totalDebit}, Credit: {$totalCredit}"
            );
        }

        // ── 3b. Approval policy check for manual journals ──────────
        $userId = TenantUserHelper::resolveTenantUserId($request->userId);
        if ($userId && $this->isManualJournal($request->documentType)) {
            $approvalCheck = $this->approvalPolicy->requiresApproval(
                'manual_journal',
                $totalDebit,
                User::find($userId),
                $request->overrideReason
            );

            if ($approvalCheck['required']) {
                Log::warning('Manual journal requires approval', [
                    'document_type' => $request->documentType,
                    'amount' => $totalDebit,
                    'threshold' => $approvalCheck['threshold'],
                ]);

                return PostingResult::failure(
                    "Approval required: {$approvalCheck['reason']}. Required roles: ".
                    implode(', ', $approvalCheck['approver_roles'])
                );
            }
        }

        // ── 3c. Authority gate evaluation (ADR-005 §8, §15) ────────
        // FAIL-CLOSED: every material posting must be bound to a tenant
        // context. Without it, evaluation cannot enforce entitlement /
        // permission / entity isolation, so the posting must be rejected
        // with a canonical reason instead of silently allowed.
        $tenantId = $request->tenantId ?? tenant()->id ?? null;
        $eligibilityResult = null;

        if (! $tenantId) {
            return PostingResult::failure(
                'Posting blocked: '.ReasonCode::MISSING_TENANT_CONTEXT->value
            );
        }

        // Resolve required permission from posting metadata or document type mapping
        $requiredPermission = $request->requiredPermission
            ?? ($request->metadata['required_permission'] ?? null)
            ?? $this->resolvePermissionForDocumentType($request->documentType);

        $eligibilityResult = $this->eligibilityEvaluator->evaluate(
            action: 'POST_JOURNAL',
            recordType: 'JournalEntry',
            recordId: $request->documentId ?? 'new',
            context: [
                'tenant_id' => (string) $tenantId,
                'entity_id' => $request->legalEntityId ? (string) $request->legalEntityId : null,
                'actor_id' => $userId ? (string) $userId : null,
                'document_type' => $request->documentType,
                'posting_date' => $request->postingDate,
                'total_amount' => $totalDebit,
                'correlation_id' => $request->correlationId,
                'required_permission' => $requiredPermission,
                'required_entitlement' => $request->requiredEntitlement ?? ($request->metadata['required_entitlement'] ?? null),
                'source_capability' => $request->sourceCapability ?? ($request->metadata['source_capability'] ?? null),
                'posting_lines' => array_map(fn ($l) => [
                    'account_mapping_key' => $l->accountMappingKey,
                    'account_id' => $l->accountId,
                    'debit' => $l->debit,
                    'credit' => $l->credit,
                ], $request->lines),
                'tax_determination_result' => $request->taxDeterminationResult ?? ($request->metadata['tax_determination_result'] ?? null),
            ],
            requireSnapshot: true,
        );

        if ($eligibilityResult->isBlocked()) {
            Log::warning('Posting blocked by authority gate', [
                'document_type' => $request->documentType,
                'blocking_reason' => $eligibilityResult->blockingReason?->value,
                'snapshot_id' => $eligibilityResult->snapshotId,
            ]);

            return PostingResult::failure(
                'Posting blocked: '.($eligibilityResult->blockingReason?->value ?? 'UNKNOWN')
            );
        }

        if ($eligibilityResult->hasError()) {
            Log::error('Authority gate evaluation error', [
                'document_type' => $request->documentType,
                'snapshot_id' => $eligibilityResult->snapshotId,
            ]);

            return PostingResult::failure('Authority gate evaluation failed');
        }

        // ── 4. Atomic create + post ───────────────────────────
        try {
            // ✅ ATOMIC
            return DB::transaction(function () use ($request, $resolvedLines, $totalDebit, $totalCredit) {
                // Idempotency pre-check inside the transaction to avoid orphan postings
                $existingLink = JournalEntryLink::where('source_document_type', $request->documentType)
                    ->where('source_document_id', $request->documentId)
                    ->where('relation_type', 'post')
                    ->lockForUpdate()
                    ->first();
                if ($existingLink) {
                    return PostingResult::success(
                        JournalEntry::find($existingLink->journal_entry_id)->load('lines'),
                        null
                    );
                }
                $userId = TenantUserHelper::requireTenantUserId($request->userId);

                $entry = JournalEntry::create([
                    'journal_id' => $request->journalId,
                    'entry_number' => $this->generateEntryNumber($request->journalId),
                    'entry_date' => $request->postingDate,
                    'type' => $request->documentType,
                    'reference' => $request->reference,
                    'description' => $request->description,
                    'status' => 'posted',
                    'posted_at' => now(),
                    'posted_by' => $userId,
                    'created_by' => $userId,
                    'legal_entity_id' => $request->legalEntityId,
                    'entity_book_id' => $request->entityBookId,
                ]);

                foreach ($resolvedLines as $line) {
                    $entry->lines()->create($line);
                }

                // TASK TAX-002: Write tax_transaction_details for tax-bearing GL lines
                $this->writeTaxTransactionDetails($entry, $request, $resolvedLines);

                // ── document → journal link ──
                // Skip when documentId is null (batch processes like FX revaluation have
                // no single source document; storing 0 or null would create a meaningless link).
                if ($request->documentId !== null) {
                    try {
                        JournalEntryLink::create([
                            'source_document_type' => $request->documentType,
                            'source_document_id' => $request->documentId,
                            'journal_entry_id' => $entry->id,
                            'relation_type' => 'post',
                        ]);
                    } catch (\Throwable $e) {
                        // If a race condition caused a unique constraint violation (two posts with
                        // the same source document), fetch the existing link and return the
                        // existing journal entry as the successful post result.
                        if (method_exists($e, 'getCode') && $e->getCode() === '23505') {
                            $existingLink = JournalEntryLink::where('source_document_type', $request->documentType)
                                ->where('source_document_id', $request->documentId)
                                ->where('relation_type', 'post')
                                ->first();
                            if ($existingLink) {
                                return PostingResult::success(JournalEntry::find($existingLink->journal_entry_id)->load('lines'), null);
                            }
                        }
                        throw $e;
                    }
                }

                // ── audit log with correlation tracking ──
                $auditLog = PostingAuditLog::create([
                    'document_type' => $request->documentType,
                    'document_id' => $request->documentId,
                    'journal_entry_id' => $entry->id,
                    'action' => 'post',
                    'actor_user_id' => $userId,
                    'approval_user_id' => $request->approvedBy,
                    'reason_code' => $request->overrideReason,
                    'payload_snapshot' => [
                        'description' => $request->description,
                        'posting_date' => $request->postingDate,
                        'lines_count' => count($resolvedLines),
                        'total_debit' => array_sum(array_column($resolvedLines, 'debit')),
                        'request_id' => $request->requestId,
                        'correlation_id' => $request->correlationId,
                        'tenant_id' => $request->tenantId,
                        'metadata' => $request->metadata,
                    ],
                ]);

                // ── state transition recording (ADR-005 §30) ──
                $this->transitionService->recordTransition(
                    aggregateType: 'JournalEntry',
                    aggregateId: (string) $entry->id,
                    dimension: StateDimension::JOURNAL_POSTING,
                    fromState: 'NOT_POSTED',
                    toState: 'POSTED',
                    context: [
                        'tenant_id' => $request->tenantId ?? tenant()->id ?? null,
                        'actor_id' => (string) $userId,
                        'actor_type' => 'HUMAN',
                        'command_id' => $request->requestId,
                        'correlation_id' => $request->correlationId,
                        'metadata' => [
                            'document_type' => $request->documentType,
                            'document_id' => $request->documentId,
                            'entry_number' => $entry->entry_number,
                            'total_debit' => $totalDebit,
                            'total_credit' => $totalCredit,
                        ],
                    ],
                );

                // ── outbox event (ADR-005 §62) ──
                // FAIL-CLOSED: outbox tenant id must come from the validated
                // tenant context above — never fabricated via Str::uuid().
                $outboxTenantId = $request->tenantId ?? tenant()->id ?? null;
                if (! $outboxTenantId) {
                    throw new \RuntimeException('Outbox event requires tenant context; refusing to fabricate UUID');
                }
                AccountingOutboxEvent::create([
                    'tenant_id' => (string) $outboxTenantId,
                    'event_type' => 'JournalPosted',
                    'event_version' => '1.0',
                    'aggregate_type' => 'JournalEntry',
                    'aggregate_id' => (string) $entry->id,
                    'aggregate_version' => 1,
                    'source_capability' => 'ACC-002',
                    'correlation_id' => $request->correlationId,
                    'causation_id' => $request->requestId,
                    'event_payload' => [
                        'journal_entry_id' => $entry->id,
                        'entry_number' => $entry->entry_number,
                        'document_type' => $request->documentType,
                        'document_id' => $request->documentId,
                        'posting_date' => $request->postingDate,
                        'total_debit' => $totalDebit,
                        'total_credit' => $totalCredit,
                        'posted_by' => $userId,
                    ],
                ]);

                return PostingResult::success($entry->load('lines'), $auditLog->id);
            });
        } catch (\Exception $e) {
            Log::error('PostingEngine::post failed', [
                'document_type' => $request->documentType,
                'document_id' => $request->documentId,
                'error' => $e->getMessage(),
            ]);

            return PostingResult::failure('Posting failed: '.$e->getMessage());
        }
    }

    /**
     * Reverse a previously posted journal entry.
     *
     * Delegates to ReversalCorrectionEngine (ADR-005 §15.3) — the single
     * authoritative reversal engine. This method provides backward-compatible
     * API for existing callers while routing through the canonical path.
     */
    public function reverse(ReversalRequest $request): PostingResult
    {
        // ── 0. Reversal Idempotency guard ────────────────────────────────
        // ✅ IDEMPOTENT
        $existingReversalLink = JournalEntryLink::where('original_journal_entry_id', $request->journalEntryId)
            ->where('relation_type', 'reversal')
            ->first();

        if ($existingReversalLink) {
            Log::info('PostingEngine::reverse — duplicate reversal prevented', [
                'original_entry_id' => $request->journalEntryId,
                'existing_reversal_id' => $existingReversalLink->journal_entry_id,
            ]);

            return PostingResult::success(
                JournalEntry::find($existingReversalLink->journal_entry_id)->load('lines'),
                null,
            );
        }

        // ── Self-approval guard ──────────────────────────────────────────────
        $actorId = TenantUserHelper::requireTenantUserId($request->actorUserId);
        $approvedBy = $request->approvalUserId;

        if ($approvedBy !== null && $approvedBy === $actorId) {
            return PostingResult::failure(
                'Self-approval of reversals is not permitted. A different approver is required.'
            );
        }

        // ── Delegate to ReversalCorrectionEngine (ADR-005 §15.3) ──────────
        $resolvedTenantId = $request->tenantId ?? tenant()->id ?? null;
        $reversalRequestDTO = new ReversalRequest(
            journalEntryId: $request->journalEntryId,
            reversalDate: $request->reversalDate,
            reason: $request->reason,
            reasonCode: $request->reasonCode,
            actorUserId: (string) $actorId,
            tenantId: $resolvedTenantId,
            correlationId: $request->correlationId,
        );

        $result = $this->reversalEngine->fullReversal($reversalRequestDTO);

        if ($result->success) {
            return PostingResult::success($result->journalEntry->load('lines'), null);
        }

        return PostingResult::failure($result->reason ?? 'Reversal failed');
    }

    /**
     * Preview the journal effect without committing.
     */
    public function preview(PostingRequest $request): array
    {
        $resolvedLines = $this->resolveLines($request->lines, $request->documentType);

        $totalDebit = array_sum(array_column($resolvedLines, 'debit'));
        $totalCredit = array_sum(array_column($resolvedLines, 'credit'));

        return [
            'lines' => $resolvedLines,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => abs($totalDebit - $totalCredit) < 0.01,
            'posting_date' => $request->postingDate,
            'description' => $request->description,
        ];
    }

    // ── Private helpers ───────────────────────────────────────

    /**
     * Run all pre-flight validators.
     */
    private function validateRequest(PostingRequest $request): void
    {
        if (empty($request->lines)) {
            throw new \InvalidArgumentException('Posting request must have at least one line.');
        }

        // Lock-date validation (maps documentType → lock type)
        $lockType = $this->mapDocumentTypeToLockType($request->documentType);

        $lockResult = $this->lockDateValidator->validateTransactionDate($lockType, $request->postingDate);

        if ($lockResult['is_locked']) {
            // Check for override with proper permission validation
            // Workstream 2 (2026-04-15): Enforce real permission checks for lock overrides
            $actorId = TenantUserHelper::requireTenantUserId($request->userId);

            if ($request->overrideReason && $request->approvedBy) {
                // Validate that the approver has permission to override lock dates
                if (! $this->lockDateValidator->canOverrideLockDate($request->approvedBy)) {
                    throw new \Exception(
                        "Approver (ID: {$request->approvedBy}) does not have permission to override lock dates. ".
                        'Required: accounting.lock_date_override permission or admin role.'
                    );
                }

                // Validate that the actor also has permission (can't post to locked period without permission)
                if (! $this->lockDateValidator->canOverrideLockDate($actorId)) {
                    throw new \Exception(
                        "User (ID: {$actorId}) does not have permission to post to locked periods. ".
                        'Required: accounting.lock_date_override permission or admin role.'
                    );
                }

                // Prevent self-approval
                if ($actorId === $request->approvedBy) {
                    throw new \Exception(
                        'Self-approval of lock date overrides is not permitted. A different approver is required.'
                    );
                }

                $this->lockDateValidator->logOverride(
                    $actorId,
                    $lockType,
                    $request->documentId,
                    $request->postingDate,
                    $request->overrideReason,
                    $request->approvedBy,
                );
            } else {
                throw new \Exception($lockResult['message']);
            }
        }

        // Fiscal-period validation
        $this->fiscalValidator->validateOrFail($request->postingDate);
    }

    /**
     * Resolve each PostingRequestLine to an array ready for JournalEntryLine::create().
     *
     * @param  PostingRequestLine[]  $lines
     */
    private function resolveLines(array $lines, string $documentType): array
    {
        $resolved = [];

        foreach ($lines as $idx => $line) {
            // Skip zero-value lines
            if (abs($line->debit) < 0.001 && abs($line->credit) < 0.001) {
                continue;
            }

            // Resolve account: explicit ID wins, otherwise map by key
            $accountId = $line->accountId
                ?? ($this->roleResolver->isRoleBased()
                    ? $this->roleResolver->resolveId($line->accountMappingKey, $documentType)
                    : $this->accountMapper->resolveId($line->accountMappingKey, $documentType));

            // Validation: every GL line must have a valid account
            if (! $accountId) {
                throw new \InvalidArgumentException('Unable to resolve account for line #'.($idx + 1));
            }

            // TASK ACC-002: Store tax identity on GL lines
            // ⚠️ RISK: null account_id possible
            $resolved[] = [
                'account_id' => $accountId,
                'debit' => round($line->debit, 2),
                'credit' => round($line->credit, 2),
                'description' => $line->description,
                'tax_code' => $line->taxCode,
                'tax_id' => $line->taxId,
                'reference' => $line->reference,
                'department_id' => $line->departmentId,
                'project_id' => $line->projectId,
                'location_id' => $line->locationId,
                'currency_code' => $line->currencyCode ?? \App\Models\Setting::getValue('currency', ['code' => 'SGD'])['code'] ?? 'SGD',
                'original_amount' => $line->originalAmount,
                'exchange_rate' => $line->exchangeRate ?? 1.0,
            ];
        }

        return $resolved;
    }

    /**
     * Map document type to lock-date transaction type.
     */
    private function mapDocumentTypeToLockType(string $documentType): string
    {
        return match ($documentType) {
            'invoice', 'credit_note' => 'sales',
            'bill', 'vendor_credit_note' => 'purchases',
            'payment', 'receipt', 'bill_payment' => 'payments',
            'pos_order' => 'sales',
            'ocr_document' => 'purchases',
            'web_order' => 'sales',
            'fx_revaluation', 'depreciation', 'asset_disposal', 'bank_adjustment' => 'journal_entries',
            default => 'journal_entries',
        };
    }

    /**
     * Generate the next entry number.
     *
     * Delegates to the Journal's own sequence when a journal is known,
     * otherwise falls back to JournalEntry::generateEntryNumber() — the
     * single canonical fallback implementation (no longer duplicated here).
     */
    private function generateEntryNumber(?int $journalId = null): string
    {
        if ($journalId) {
            $journal = Journal::find($journalId);
            if ($journal) {
                return $journal->getNextEntryNumber();
            }
        }

        return JournalEntry::generateEntryNumber();
    }

    private function isManualJournal(string $documentType): bool
    {
        return in_array($documentType, [
            'manual_journal',
            'journal_entry',
            'adjustment',
        ]);
    }

    /**
     * Create a draft journal entry via PostingEngine.
     *
     * All draft creation MUST go through PostingEngine to maintain audit continuity.
     * Draft entries can be posted later via ManualJournalPostingService::post().
     */
    public function createDraft(DraftJournalRequest $request): DraftJournalResult
    {
        // ── 1. Validate entry date ─────────────────────────────
        $lockResult = $this->lockDateValidator->validateTransactionDate(
            'journal_entries',
            $request->entryDate,
        );

        if ($lockResult['is_locked']) {
            return DraftJournalResult::failure($lockResult['message']);
        }

        $fiscalResult = $this->fiscalValidator->validateDate($request->entryDate);
        if (! $fiscalResult['allowed']) {
            return DraftJournalResult::failure($fiscalResult['reason']);
        }

        // ── 2. Validate balance ────────────────────────────────
        $totalDebit = array_sum(array_column($request->lines, 'debit'));
        $totalCredit = array_sum(array_column($request->lines, 'credit'));

        if (abs($totalDebit - $totalCredit) > 0.01) {
            return DraftJournalResult::failure(
                "Journal entry is unbalanced. Debit: {$totalDebit}, Credit: {$totalCredit}"
            );
        }

        // ── 3. Create draft entry ─────────────────────────────
        try {
            // ✅ ATOMIC
            return DB::transaction(function () use ($request) {
                $userId = TenantUserHelper::resolveTenantUserId($request->userId);

                // Generate entry number
                $journal = $request->journalId ? Journal::find($request->journalId) : null;
                $entryNumber = $journal
                    ? $journal->getNextEntryNumber()
                    : JournalEntry::generateEntryNumber();

                $entry = JournalEntry::create([
                    'journal_id' => $request->journalId,
                    'entry_number' => $entryNumber,
                    'entry_date' => $request->entryDate,
                    'type' => 'manual',
                    'reference' => $request->reference,
                    'description' => $request->description,
                    'status' => 'draft',
                    'created_by' => $userId,
                    'legal_entity_id' => $request->legalEntityId,
                    'entity_book_id' => $request->entityBookId,
                ]);

                foreach ($request->lines as $line) {
                    $lineAmount = $line->debit > 0 ? $line->debit : $line->credit;

                    $entry->lines()->create([
                        'account_id' => $line->accountId,
                        'debit' => $line->debit,
                        'credit' => $line->credit,
                        'description' => $line->description,
                        'reference' => $line->reference,
                'currency_code' => $line->currencyCode ?? \App\Models\Setting::getValue('currency', ['code' => 'SGD'])['code'] ?? 'SGD',
                        'original_amount' => $line->originalAmount ?? $lineAmount,
                        'exchange_rate' => $line->exchangeRate ?? 1.0,
                        'department_id' => $line->departmentId,
                        'project_id' => $line->projectId,
                        'location_id' => $line->locationId,
                    ]);
                }

                Log::info('Draft journal entry created via PostingEngine', [
                    'entry_id' => $entry->id,
                    'entry_number' => $entryNumber,
                    'created_by' => $userId,
                ]);

                return DraftJournalResult::success($entry->load('lines'));
            });
        } catch (\Exception $e) {
            Log::error('PostingEngine::createDraft failed', [
                'error' => $e->getMessage(),
            ]);

            return DraftJournalResult::failure('Failed to create draft: '.$e->getMessage());
        }
    }

    /**
     * Create a replacement draft journal entry for correction workflows.
     *
     * This method supersedes the JournalEntry::create() call in ReplacementDraftService,
     * ensuring all draft creation goes through PostingEngine for audit continuity.
     */
    public function createReplacementDraft(JournalEntry $original, CorrectionRequest $request): DraftJournalResult
    {
        $correctionGroupId = $original->correction_group_id ?? Str::uuid()->toString();
        $nextVersion = ($original->version_number ?? 1) + 1;

        $lines = array_map(function ($originalLine) {
            return DraftJournalLine::fromArray([
                'account_id' => $originalLine->account_id,
                'debit' => $originalLine->debit,
                'credit' => $originalLine->credit,
                'description' => $originalLine->description,
                'reference' => $originalLine->reference,
                'currency_code' => $originalLine->currency_code,
                'original_amount' => $originalLine->original_amount,
                'exchange_rate' => $originalLine->exchange_rate,
                'department_id' => $originalLine->department_id,
                'project_id' => $originalLine->project_id,
                'location_id' => $originalLine->location_id,
            ]);
        }, $original->lines->all());

        $draftRequest = DraftJournalRequest::fromArray([
            'entry_date' => $original->entry_date->toDateString(),
            'description' => $original->description,
            'lines' => array_map(fn ($line) => [
                'account_id' => $line->accountId,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'description' => $line->description,
                'reference' => $line->reference,
            ], $lines),
            'reference' => $original->reference,
            'user_id' => $request->userId,
            'legal_entity_id' => $original->legal_entity_id,
            'entity_book_id' => $original->entity_book_id,
        ]);

        $result = $this->createDraft($draftRequest);

        if ($result->success && $result->journalEntry) {
            $result->journalEntry->update([
                'entry_number' => $original->entry_number.'-R'.str_pad($nextVersion, 2, '0', STR_PAD_LEFT),
                'type' => $original->type,
                'origin_type' => $original->origin_type,
                'origin_reference_type' => 'journal_entry',
                'origin_reference_id' => $original->id,
                'lifecycle_status' => 'draft',
                'correction_group_id' => $correctionGroupId,
                'original_document_id' => $original->id,
                'correction_type' => 'replacement',
                'correction_reason_code' => $request->reasonCode,
                'correction_reason_text' => $request->reasonText,
                'version_number' => $nextVersion,
                'is_active_version' => true,
            ]);

            $original->update([
                'is_active_version' => false,
                'lifecycle_status' => 'superseded',
                'superseded_at' => now(),
                'replacement_document_id' => $result->journalEntry->id,
            ]);
        }

        return $result;
    }

    /**
     * TASK TAX-002: Write tax_transaction_details for tax-bearing GL lines
     *
     * Creates durable tax audit records atomically for every tax-bearing GL posting.
     * Links GL lines to tax reporting and enables tax reconciliation.
     *
     * @param  JournalEntry  $entry  The journal entry that was just created
     * @param  PostingRequest  $request  The original posting request
     * @param  array  $resolvedLines  The resolved GL lines
     */
    private function writeTaxTransactionDetails(
        JournalEntry $entry,
        PostingRequest $request,
        array $resolvedLines
    ): void {
        foreach ($resolvedLines as $line) {
            // Only write tax details for lines with tax_code
            if (empty($line['tax_code'])) {
                continue;
            }

            // Calculate reporting period (YYYY-MM format)
            $reportingPeriod = date('Y-m', strtotime($request->postingDate));

            // Determine taxable amount and tax amount based on debit/credit
            // For sales tax payable (credit), the credit is the tax amount
            // For tax receivable (debit), the debit is the tax amount
            $taxAmount = max($line['debit'], $line['credit']);

            // Taxable amount derived from real tax determination context (ACC-009)
            // The tax_determination_result carries the actual rate and taxable base;
            // when absent, we compute from the tax line and rate if available.
            $taxRate = $request->taxDeterminationResult['tax_rate']
                ?? $request->metadata['tax_determination_result']['tax_rate']
                ?? null;
            $taxableAmount = 0;
            if ($taxAmount > 0) {
                if ($taxRate !== null && $taxRate > 0) {
                    $taxableAmount = $taxAmount / $taxRate;
                } else {
                    // Fallback: log warning, do not fabricate a rate
                    Log::warning('Tax transaction detail written without resolvable tax rate', [
                        'journal_entry_id' => $entry->id,
                        'tax_code' => $line['tax_code'],
                    ]);
                    $taxableAmount = 0;
                }
            }

            DB::table('tax_transaction_details')->insert([
                'journal_entry_id' => $entry->id,
                'source_document_type' => $request->documentType,
                'source_document_id' => $request->documentId ?? 0,
                'tax_code' => $line['tax_code'],
                'tax_rule_id' => $line['tax_id'],
                'taxable_amount' => round($taxableAmount, 2),
                'tax_amount' => round($taxAmount, 2),
                'jurisdiction' => null, // Could be enhanced with jurisdiction logic
                'reporting_period' => $reportingPeriod,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('Tax transaction detail written', [
                'journal_entry_id' => $entry->id,
                'tax_code' => $line['tax_code'],
                'tax_amount' => $taxAmount,
                'reporting_period' => $reportingPeriod,
            ]);
        }
    }

    /**
     * Resolve the required permission string for a given document type.
     * This maps operational document types to their corresponding
     * permission-checkable strings for the authority gate framework.
     */
    private function resolvePermissionForDocumentType(string $documentType): ?string
    {
        return match ($documentType) {
            'invoice', 'sales_invoice', 'credit_note' => 'accounting.write',
            'bill', 'purchase_invoice', 'debit_note' => 'accounting.write',
            'payment', 'bill_payment' => 'accounting.write',
            'journal_entry' => 'accounting.write',
            'adjustment' => 'accounting.write',
            'reversal' => 'accounting.reverse',
            // POS, bank reconciliation, asset, and other module-specific types
            // are expected to provide required_permission in metadata or skip
            // the permission gate via module-level RBAC instead.
            default => null,
        };
    }
}
