<?php

namespace App\Accounting;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\DraftJournalRequest;
use App\Accounting\DTOs\DraftJournalResult;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\PostingResult;
use App\Accounting\DTOs\ReversalRequest;
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
use App\Support\TenantUserHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
                // Issue #68: a superseded reference is history, not a duplicate.
                ->whereNull('superseded_at')
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
                'action' => 'POST_JOURNAL',
                'tenant_id' => (string) $tenantId,
                'entity_id' => $request->legalEntityId ? (string) $request->legalEntityId : null,
                // #278: numeric entity scope for the tax-period lock gate.
                'legal_entity_id' => $request->legalEntityId,
                'actor_id' => $userId ? (string) $userId : null,
                'document_type' => $request->documentType,
                'posting_date' => $request->postingDate,
                'total_amount' => $totalDebit,
                'correlation_id' => $request->correlationId,
                'required_permission' => $requiredPermission,
                'required_entitlement' => $request->requiredEntitlement ?? ($request->metadata['required_entitlement'] ?? null),
                'source_capability' => $request->sourceCapability ?? ($request->metadata['source_capability'] ?? null),
                // ── P1-8: Concurrency gate context (ADR-005 §8.5) ──
                // Caller may supply expectedRecordVersion + currentRecordVersion
                // to enable optimistic locking on the source aggregate.
                'expected_record_version' => $request->expectedRecordVersion
                    ?? ($request->metadata['expected_record_version'] ?? null),
                'current_record_version' => $request->currentRecordVersion
                    ?? ($request->metadata['current_record_version'] ?? null),
                'posting_lines' => array_map(fn ($l) => [
                    'account_mapping_key' => $l->accountMappingKey,
                    'account_id' => $l->accountId,
                    'debit' => $l->debit,
                    'credit' => $l->credit,
                    // Issue #42 (HR42-01): tax identity must reach TaxGate or
                    // the gate can never detect a tax-bearing line and fails
                    // open. Without these the gate was always NOT_APPLICABLE.
                    'tax_code' => $l->taxCode,
                    'tax_id' => $l->taxId,
                ], $request->lines),
                'tax_determination_result' => $request->taxDeterminationResult ?? ($request->metadata['tax_determination_result'] ?? null),
                // G-08 (#274): caller-expected determination version — the
                // TaxGate blocks when the supplied snapshot does not match it.
                'expected_tax_result_version' => $request->expectedTaxResultVersion
                    ?? ($request->metadata['expected_tax_result_version'] ?? null),
                // HR69-01: thread period admission context so PeriodOpenGate
                // can bind the mandatory posting period (Opening Balance, etc.)
                // rather than resolving to NOT_APPLICABLE on a missing period.
                'period_id' => $request->metadata['period_id'] ?? ($request->metadata['fiscal_period_id'] ?? null),
                'period_required' => $request->metadata['period_required'] ?? false,
                // Issue #66 (HR66-03): the source document identity must be in
                // the gate context or AssuranceGate cannot resolve the
                // document's findings and the real posting path always passes.
                // Matching is source_type = documentType plus the UUID
                // source_id or the document reference_number.
                'record_type' => $request->documentType,
                'record_id' => $request->documentId !== null ? (string) $request->documentId : null,
                'record_reference' => $request->reference,
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
            return DB::transaction(function () use ($request, $resolvedLines, $totalDebit, $totalCredit, $tenantId) {
                // #278 F4: commit-time lock re-check — a tax period locked
                // AFTER gate evaluation must still reject the posting (no
                // lost update between a concurrent close and this commit).
                if ($this->postingIsTaxRelevant($request)) {
                    $commitPeriod = date('Y-m', strtotime($request->postingDate));

                    $lockedAtCommit = DB::table('tax_period_locks')
                        ->where('period', $commitPeriod)
                        ->where('tenant_id', (int) $tenantId)
                        ->where(function ($q) use ($request) {
                            $q->whereNull('legal_entity_id');

                            if ($request->legalEntityId) {
                                $q->orWhere('legal_entity_id', (int) $request->legalEntityId);
                            }
                        })
                        ->exists();

                    if ($lockedAtCommit) {
                        throw new \RuntimeException(
                            'TAX_PERIOD_LOCKED: tax period '.$commitPeriod.' was closed while this posting was in flight'
                        );
                    }
                }

                // Idempotency pre-check inside the transaction to avoid orphan postings.
                // Issue #68: superseded references are history, not duplicates.
                $existingLink = JournalEntryLink::where('source_document_type', $request->documentType)
                    ->where('source_document_id', $request->documentId)
                    ->where('relation_type', 'post')
                    ->whereNull('superseded_at')
                    ->lockForUpdate()
                    ->first();
                if ($existingLink) {
                    return PostingResult::success(
                        JournalEntry::find($existingLink->journal_entry_id)->load('lines'),
                        null
                    );
                }
                $userId = TenantUserHelper::requireTenantUserId($request->userId);

                // #878 (SA4): non-interactive postings are attributed to the
                // tenant's dedicated system actor, with the originating
                // trigger recorded (job / schedule / webhook id). Interactive
                // postings resolve to the real authenticated user or fail
                // closed — never to an arbitrary lowest-ID user.
                $isSystemActor = TenantUserHelper::isSystemActor($userId);
                $postingTrigger = $request->trigger ?? ($request->metadata['trigger'] ?? null);
                if ($postingTrigger === null && $isSystemActor) {
                    $postingTrigger = 'background';
                }
                $triggerType = $postingTrigger !== null ? explode(':', $postingTrigger, 2)[0] : null;

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
                    ...($isSystemActor ? [
                        'origin_type' => 'system',
                        'origin_context' => [
                            'actor_type' => 'SYSTEM',
                            'system_actor_id' => $userId,
                            'trigger' => $postingTrigger,
                            'trigger_type' => $triggerType,
                        ],
                    ] : []),
                    // MRD pricelist-multicurrency FR-5.7/§12: header FX stamp
                    // from the DOCUMENT snapshot (columns existed since
                    // 2025_12_30_062429 but had no writer). Only set when the
                    // caller supplied a document currency, so base-currency
                    // postings keep the historical NULL shape byte-identical.
                    ...($request->currencyCode !== null ? [
                        'original_currency_code' => $request->currencyCode,
                        'original_total_amount' => $this->originalTotalForHeader($resolvedLines),
                        'exchange_rate' => $request->exchangeRate,
                    ] : []),
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
                                ->whereNull('superseded_at')
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
                        // #878 (SA4): actor type + originating trigger are
                        // part of the immutable posting audit trail.
                        'actor_type' => $isSystemActor ? 'SYSTEM' : 'HUMAN',
                        'posting_trigger' => $postingTrigger,
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
                        'actor_type' => $isSystemActor ? 'SYSTEM' : 'HUMAN',
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
                        'actor_type' => $isSystemActor ? 'SYSTEM' : 'HUMAN',
                        'posting_trigger' => $postingTrigger,
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
     * Post via DomainPostingRequest — the controlled contract for operational
     * capabilities (ACC-003 AR, ACC-004 AP, ACC-005A payments, ACC-006 banking,
     * ACC-007 assets, ACC-008 FX, ACC-009 tax, ACC-011 close).
     *
     * ADR-005 §12 — operational domains may NOT submit unrestricted debit/credit
     * lines. Instead they submit business values (account roles + amounts) and
     * the PostingPolicyMapper resolves them into controlled ledger lines via a
     * versioned posting profile.
     *
     * This method exists alongside post(PostingRequest) so existing callers
     * keep working during migration. New operational services should call
     * domainPosting() with a DomainPostingRequest.
     */
    public function domainPosting(\App\Accounting\DTOs\DomainPostingRequest $request): PostingResult
    {
        // ── 1. Idempotency guard ─────────────────────────────
        $existingLink = \App\Models\JournalEntryLink::where('source_document_type', $request->sourceRecordType)
            ->where('source_document_id', $request->sourceRecordId)
            ->where('relation_type', 'post')
            ->first();

        if ($existingLink) {
            Log::info('PostingEngine::domainPosting — duplicate posting prevented', [
                'source_record_type' => $request->sourceRecordType,
                'source_record_id' => $request->sourceRecordId,
                'entry_id' => $existingLink->journal_entry_id,
                'request_id' => $request->postingRequestId,
            ]);

            return PostingResult::success(
                JournalEntry::find($existingLink->journal_entry_id),
                null,
            );
        }

        // ── 2. Resolve via PostingPolicyMapper ────────────────
        $policyMapper = app(\App\Accounting\Services\PostingPolicyMapper::class);
        $resolution = $policyMapper->resolve($request);

        if (! $resolution->success) {
            return PostingResult::failure('Posting policy resolution failed: '.($resolution->error ?? 'unknown'));
        }

        // ── 3. Verify all resolved lines have account_id ──────
        $unresolvedLines = [];
        foreach ($resolution->ledgerLines as $idx => $line) {
            if (empty($line['account_id'])) {
                $unresolvedLines[] = ($line['account_role'] ?? 'unknown')." (line {$idx})";
            }
        }

        if (! empty($unresolvedLines)) {
            return PostingResult::failure(
                'Account role(s) could not be resolved to a chart_of_accounts.id: '.
                implode(', ', $unresolvedLines).
                '. Configure via Entity Account Roles or update the posting profile.'
            );
        }

        // ── 4. Resolve the legal entity / book (Issue #875) ───
        // A domain posting that cannot be bound to an entity in a tenant
        // that HAS entities would write journal_entries.legal_entity_id =
        // NULL: invisible to entity-filtered reports and to entity-scoped
        // users (scopeAccessibleBy fails closed on NULL). Reject it.
        /** @var \App\Services\LegalEntityContextResolver $entityResolver */
        $entityResolver = app(\App\Services\LegalEntityContextResolver::class);

        $legalEntityId = ($request->entityId !== null && $request->entityId !== '' && is_numeric($request->entityId))
            ? (int) $request->entityId
            : null;

        if (! $legalEntityId) {
            $legalEntityId = $entityResolver->fromRequestContext() ?? $entityResolver->singleActiveEntityId();
        }

        if (! $legalEntityId && $entityResolver->entityExists()) {
            return PostingResult::failure(
                'Posting blocked: '.ReasonCode::MISSING_ENTITY_CONTEXT->value
            );
        }

        $entityBookId = ($request->accountingBookId !== null && $request->accountingBookId !== '' && is_numeric($request->accountingBookId))
            ? (int) $request->accountingBookId
            : $entityResolver->resolveBookId($legalEntityId);

        // ── 5. Build PostingRequest from resolved lines ───────
        $postingRequestLines = [];
        foreach ($resolution->ledgerLines as $line) {
            // Issue #876 (SA2): the mapper merges request- and line-level
            // dimensions; the journal columns are the analytic ids.
            $dimensions = is_array($line['dimensions'] ?? null) ? $line['dimensions'] : [];
            $postingRequestLines[] = new PostingRequestLine(
                accountMappingKey: $line['account_role'] ?? 'unknown',
                accountId: $line['account_id'],
                debit: (float) $line['debit'],
                credit: (float) $line['credit'],
                description: $line['description'] ?? "Posting profile {$request->postingProfileCode}",
                departmentId: isset($dimensions['department_id']) ? (int) $dimensions['department_id'] : null,
                projectId: isset($dimensions['project_id']) ? (int) $dimensions['project_id'] : null,
                locationId: isset($dimensions['location_id']) ? (int) $dimensions['location_id'] : null,
                currencyCode: $line['currency_code'] ?? $request->currencyCode,
                originalAmount: $line['original_amount'] ?? null,
                exchangeRate: $line['exchange_rate'] ?? null,
                taxCode: $line['tax_code'] ?? null,
                taxId: $line['tax_id'] ?? null,
            );
        }

        $postingRequest = new PostingRequest(
            documentType: $request->sourceRecordType,
            documentId: is_numeric($request->sourceRecordId) ? (int) $request->sourceRecordId : null,
            postingDate: $request->postingDate,
            description: $request->ledgerDescription ?? "Domain posting: {$request->sourceCapability}/{$request->postingProfileCode}",
            lines: $postingRequestLines,
            reference: $request->reference,
            currencyCode: $request->currencyCode,
            exchangeRate: $request->exchangeRate,
            requestId: $request->postingRequestId,
            userId: $request->userId,
            tenantId: null, // tenancy resolved by middleware
            sourceCapability: $request->sourceCapability,
            taxDeterminationResult: $request->taxDeterminationResult,
            legalEntityId: $legalEntityId,
            entityBookId: $entityBookId,
        );

        // ── 6. Delegate to canonical post() path ──────────────
        return $this->post($postingRequest);
    }

    /**
     * Sum of the ORIGINAL (document-currency) amounts on the credit side of
     * the entry — informational header column for dual display. Falls back to
     * the debit side when the posting has no credits carrying originals.
     *
     * @param  array<int,array<string,mixed>>  $resolvedLines
     */
    private function originalTotalForHeader(array $resolvedLines): ?float
    {
        foreach (['credit', 'debit'] as $side) {
            $sum = 0.0;

            foreach ($resolvedLines as $line) {
                if ((float) $line[$side] != 0 && ($line['original_amount'] ?? null) !== null) {
                    $sum += (float) $line['original_amount'];
                }
            }

            if ($sum != 0.0) {
                return round($sum, 2);
            }
        }

        return null;
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
     * Issue #68 (HR68-02): ACC-002-owned retirement of a source posting
     * reference. The original link is marked superseded (never deleted) so the
     * lineage original request -> original link -> original JE -> reversal ->
     * reopen -> fresh posting stays reconstructable, while the idempotency
     * guard no longer treats the retired reference as a duplicate.
     */
    public function retirePostingReference(int $originalJournalEntryId, ?int $reversalJournalEntryId, string $reason): void
    {
        $retired = JournalEntryLink::where('journal_entry_id', $originalJournalEntryId)
            ->where('relation_type', 'post')
            ->whereNull('superseded_at')
            ->update([
                'superseded_at' => now(),
                'superseded_by_journal_entry_id' => $reversalJournalEntryId,
            ]);

        if ($retired > 0) {
            Log::info('PostingEngine::retirePostingReference — source posting reference superseded', [
                'original_journal_entry_id' => $originalJournalEntryId,
                'superseded_by_journal_entry_id' => $reversalJournalEntryId,
                'reason' => $reason,
            ]);
        }
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

        $actorId = null;

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

        // Fiscal-period validation — evaluated against the same resolved actor
        // (and, when a lock-date override was approved, its approver), so a
        // properly vetted override carries across both gates (issue #166).
        $actorId ??= TenantUserHelper::resolveTenantUserId($request->userId);
        $fiscalUser = $this->resolveFiscalOverrideUser($actorId, $request->approvedBy);
        $fiscalResult = $this->fiscalValidator->validateDate($request->postingDate, $fiscalUser, $request->legalEntityId);

        if (! $fiscalResult['allowed'] && $fiscalUser && $actorId !== null && (int) $fiscalUser->id !== $actorId) {
            // Fall back to the actor's own override authority.
            $fiscalResult = $this->fiscalValidator->validateDate($request->postingDate, User::find($actorId), $request->legalEntityId);
            $fiscalUser = User::find($actorId);
        }

        if (! $fiscalResult['allowed']) {
            throw new \Exception($fiscalResult['reason'] ?? 'Fiscal period validation failed.');
        }

        if (($fiscalResult['override'] ?? false) && $fiscalUser && isset($fiscalResult['period'])) {
            $this->fiscalValidator->logOverride(
                $fiscalResult['period'],
                $fiscalUser,
                'posting with period override',
            );
        }
    }

    /**
     * Issue #166: the fiscal-period gate must evaluate a real, resolved user.
     * When a lock-date override was approved, prefer the approver (the override
     * authority); otherwise the actor.
     */
    private function resolveFiscalOverrideUser(?int $actorId, ?int $approvedBy): ?User
    {
        if ($approvedBy !== null) {
            $approver = User::find($approvedBy);

            if ($approver) {
                return $approver;
            }
        }

        return $actorId !== null ? User::find($actorId) : null;
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
                'tax_direction' => $line->taxDirection,
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
            'payment', 'receipt', 'bill_payment', 'payment_settlement' => 'payments',
            'pos_order' => 'sales',
            'ocr_document' => 'purchases',
            'web_order' => 'sales',
            'storefront_order', 'storefront_refund', 'storefront_return_restock', 'event_booking' => 'sales',
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

        // Issue #166: evaluate the resolved actor, not ambient auth()->user().
        $fiscalUser = $request->userId ? User::find($request->userId) : null;
        $fiscalResult = $this->fiscalValidator->validateDate($request->entryDate, $fiscalUser, $request->legalEntityId);
        if (! $fiscalResult['allowed']) {
            return DraftJournalResult::failure($fiscalResult['reason']);
        }

        if (($fiscalResult['override'] ?? false) && $fiscalUser && isset($fiscalResult['period'])) {
            $this->fiscalValidator->logOverride($fiscalResult['period'], $fiscalUser, 'draft creation with period override');
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
                    'remark' => $request->remark,
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
        $codedCodes = [];

        foreach ($resolvedLines as $line) {
            // Only write tax details for lines with tax_code
            if (empty($line['tax_code'])) {
                continue;
            }

            $codedCodes[(string) $line['tax_code']] = true;

            // Calculate reporting period (YYYY-MM format)
            $reportingPeriod = date('Y-m', strtotime($request->postingDate));

            // Determine tax amount based on debit/credit
            // For sales tax payable (credit), the credit is the tax amount
            // For tax receivable (debit), the debit is the tax amount
            $taxAmount = max($line['debit'], $line['credit']);

            // #42: Use the canonical TaxDeterminationResult persisted values
            // (rate, taxable_base, treatment, regime, version). When the
            // determination is missing for a tax-bearing line, we must NOT
            // divide to infer the taxable base — that is a hard-coded rate
            // assumption. Block the posting with TAX_RESULT_MISSING.
            $taxDeterminationResult = $request->taxDeterminationResult
                ?? ($request->metadata['tax_determination_result'] ?? null);

            // Match by tax_code when the determination covers multiple lines.
            $lineDetermination = $this->resolveLineDetermination($taxDeterminationResult, $line);

            if ($lineDetermination === null) {
                // G-05..G-09 (#274): NEVER write zero-valued trail rows after
                // the fact. This runs inside the posting transaction, so
                // throwing rolls back the journal entry — the posting fails
                // closed with TAX_RESULT_MISSING instead of leaving a
                // ledger-only tax event with fabricated zero evidence.
                Log::error('Tax-bearing line has no resolvable TaxDeterminationResult — aborting posting', [
                    'journal_entry_id' => $entry->id,
                    'tax_code' => $line['tax_code'],
                ]);

                throw new \RuntimeException(
                    'TAX_RESULT_MISSING: no TaxDeterminationResult line for tax code '.$line['tax_code']
                );
            }

            $taxableAmount = (float) ($lineDetermination['taxable_base']
                ?? $lineDetermination['taxable_amount']
                ?? 0);
            $taxRate = $lineDetermination['tax_rate'] ?? null;
            $treatment = $lineDetermination['treatment']
                ?? ($lineDetermination['result_status'] ?? null);
            $regime = $lineDetermination['tax_regime'] ?? null;
            $resultVersion = $lineDetermination['result_version'] ?? null;
            $determinationId = $lineDetermination['id'] ?? null;

            DB::table('tax_transaction_details')->insert([
                'journal_entry_id' => $entry->id,
                'source_document_type' => $request->documentType,
                'source_document_id' => $request->documentId ?? 0,
                'tax_code' => $line['tax_code'],
                'tax_rule_id' => $line['tax_id'],
                'taxable_amount' => round($taxableAmount, 2),
                'tax_amount' => round($taxAmount, 2),
                'jurisdiction' => $lineDetermination['jurisdiction'] ?? null,
                'direction' => $line['tax_direction'] ?? null,
                'reporting_period' => $reportingPeriod,
                'tax_determination_id' => $determinationId,
                'tax_rate' => $taxRate,
                'tax_treatment' => $treatment,
                'tax_regime' => $regime,
                'result_version' => $resultVersion,
                // G-39 (#272): document currency + booking FX on the trail.
                'currency_code' => $request->currencyCode,
                'exchange_rate' => $request->exchangeRate,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('Tax transaction detail written', [
                'journal_entry_id' => $entry->id,
                'tax_code' => $line['tax_code'],
                'tax_amount' => $taxAmount,
                'taxable_amount' => $taxableAmount,
                'tax_rate' => $taxRate,
                'reporting_period' => $reportingPeriod,
            ]);
        }

        // MRD gap review G-32: zero-rated and exempt supplies produce no GL
        // tax line (there is no tax amount), yet IRAS F5 boxes 2/3 and the
        // SST zero-rated breakdown REQUIRE them on the trail. Write one supply
        // row per determination code that (a) carries no GL counterpart and
        // (b) is a declared zero-rated/exempt treatment. out_of_scope/NO-TAX
        // rows stay out — they are not supplies.
        $determinationForSupplies = $request->taxDeterminationResult
            ?? ($request->metadata['tax_determination_result'] ?? null);

        foreach (($determinationForSupplies['tax_lines'] ?? []) as $taxLine) {
            $code = (string) ($taxLine['tax_code'] ?? '');
            $treatment = strtolower((string) ($taxLine['treatment'] ?? ''));

            if ($code === '' || isset($codedCodes[$code])) {
                continue;
            }

            if (! in_array($treatment, ['zero_rated', 'exempt'], true)) {
                continue;
            }

            if (abs((float) ($taxLine['tax_amount'] ?? 0)) > 0.005) {
                // A nonzero-amount code without a GL line is an accounting bug,
                // not a supply row; the per-line loop above owns it.
                continue;
            }

            DB::table('tax_transaction_details')->insert([
                'journal_entry_id' => $entry->id,
                'source_document_type' => $request->documentType,
                'source_document_id' => $request->documentId ?? 0,
                'tax_code' => $code,
                'tax_rule_id' => $taxLine['tax_id'] ?? null,
                'taxable_amount' => round((float) ($taxLine['taxable_base'] ?? 0), 2),
                'tax_amount' => 0,
                'jurisdiction' => $determinationForSupplies['jurisdiction'] ?? null,
                'direction' => 'supply',
                'reporting_period' => date('Y-m', strtotime($request->postingDate)),
                'tax_determination_id' => $determinationForSupplies['id'] ?? null,
                'tax_rate' => $taxLine['tax_rate'] ?? null,
                'tax_treatment' => $treatment,
                'tax_regime' => $determinationForSupplies['tax_regime'] ?? null,
                'result_version' => $determinationForSupplies['result_version'] ?? null,
                // G-39 (#272): document currency + booking FX on the trail.
                'currency_code' => $request->currencyCode,
                'exchange_rate' => $request->exchangeRate,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $codedCodes[$code] = true;
        }
    }

    /**
     * #278: tax-relevant = coded tax lines or a determination with tax lines
     * (supply-only documents included).
     */
    private function postingIsTaxRelevant(PostingRequest $request): bool
    {
        foreach ($request->lines as $line) {
            if ($line->taxCode !== null || $line->taxId !== null) {
                return true;
            }
        }

        $determination = $request->taxDeterminationResult ?? ($request->metadata['tax_determination_result'] ?? null);

        return is_array($determination) && ! empty($determination['tax_lines']);
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
            'payment', 'bill_payment', 'payment_refund', 'payment_settlement' => 'accounting.write',
            'journal_entry' => 'accounting.write',
            'adjustment' => 'accounting.write',
            // Issue #106: manual stock adjustments route here; the actor holds
            // the inventory operation permission, the GL entry is a consequence.
            'stock_adjustment' => 'inventory.write',
            // Issue #105: goods receipt recognition is an inventory operation.
            'goods_receipt' => 'inventory.write',
            'reversal' => 'accounting.reverse',
            // Vendor document variants (module postings that create AP GL lines).
            'vendor_credit_note', 'vendor_debit_note' => 'accounting.write',
            // Accountant-owned ledger operations.
            'opening_balance', 'period_close', 'bank_adjustment',
            'fx_revaluation', 'intercompany', 'asset_disposal',
            'payroll_run', 'payroll_payment' => 'accounting.write',
            // Retail / ecommerce sales posting — coarse module permission held
            // by the actors who create these documents (module-level RBAC aligned).
            'pos_order', 'pos_refund_payment' => 'pos.create',
            'web_order', 'event_booking' => 'sales.write',
            // Storefront module sales posting — its own coarse permission
            // (seeded matrix + RequirePermission parity, FR-MOD-03).
            'storefront_order', 'storefront_refund', 'storefront_return_restock' => 'storefront.write',
            // Issue #176: DO completion recognises COGS — a sales fulfilment
            // action performed by the same actors who create web orders.
            'delivery_order' => 'sales.write',
            // Issue #94: fixed-asset impairment posting.
            'asset_impairment' => 'accounting.write',
            // Genuinely unknown document types resolve to null; the
            // PermissionGate treats a null required_permission on a material
            // posting action as a fail-closed ERROR (#37 AC4).
            default => null,
        };
    }

    /**
     * Match a PostingRequest line to its corresponding tax-line entry in the
     * canonical TaxDeterminationResult (#42). When the determination covers
     * multiple tax lines (per tax_code), we look up the matching tax_code;
     * otherwise the determination is treated as a single-line entry.
     */
    private function resolveLineDetermination(?array $determination, array $line): ?array
    {
        if ($determination === null) {
            return null;
        }

        $taxLines = $determination['tax_lines'] ?? null;
        if (is_array($taxLines) && ! empty($taxLines)) {
            foreach ($taxLines as $taxLine) {
                if (($taxLine['tax_code'] ?? null) === ($line['tax_code'] ?? null)) {
                    return array_merge($determination, $taxLine);
                }
            }

            return null;
        }

        return $determination;
    }
}
