<?php

namespace App\Accounting;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\PostingResult;
use App\Accounting\DTOs\ReversalRequest;
use App\Accounting\Services\AccountMappingService;
use App\Accounting\Services\AccountRoleResolver;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\PostingAuditLog;
use App\Services\FiscalPeriodValidator;
use App\Services\LockDateValidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class PostingEngine implements PostingEngineInterface
{
    public function __construct(
        private readonly AccountMappingService $accountMapper,
        private readonly AccountRoleResolver $roleResolver,
        private readonly LockDateValidationService $lockDateValidator,
        private readonly FiscalPeriodValidator $fiscalValidator,
    ) {}

    /**
     * Post a business document to the ledger.
     */
    public function post(PostingRequest $request): PostingResult
    {
        // ── 0. Idempotency guard ─────────────────────────────
        if ($request->documentId !== null) {
            $existingLink = JournalEntryLink::where('source_document_type', $request->documentType)
                ->where('source_document_id', $request->documentId)
                ->where('relation_type', 'post')
                ->first();

            if ($existingLink) {
                Log::info('PostingEngine::post — duplicate posting prevented', [
                    'document_type' => $request->documentType,
                    'document_id'   => $request->documentId,
                    'entry_id'      => $existingLink->journal_entry_id,
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
            return PostingResult::failure("Account resolution failed: " . $e->getMessage());
        }

        // ── 3. Balance check ──────────────────────────────────
        $totalDebit  = array_sum(array_column($resolvedLines, 'debit'));
        $totalCredit = array_sum(array_column($resolvedLines, 'credit'));

        if (abs($totalDebit - $totalCredit) > 0.01) {
            return PostingResult::failure(
                "Journal entry is unbalanced. Debit: {$totalDebit}, Credit: {$totalCredit}"
            );
        }

        // ── 4. Atomic create + post ───────────────────────────
        try {
            return DB::transaction(function () use ($request, $resolvedLines) {
                $userId = TenantUserHelper::requireTenantUserId($request->userId);

                $entry = JournalEntry::create([
                    'journal_id'   => $request->journalId,
                    'entry_number' => $this->generateEntryNumber($request->journalId),
                    'entry_date'   => $request->postingDate,
                    'type'         => $request->documentType,
                    'reference'    => $request->reference,
                    'description'  => $request->description,
                    'status'       => 'posted',
                    'posted_at'    => now(),
                    'posted_by'    => $userId,
                    'created_by'   => $userId,
                    'legal_entity_id' => $request->legalEntityId,
                    'entity_book_id'  => $request->entityBookId,
                ]);

                foreach ($resolvedLines as $line) {
                    $entry->lines()->create($line);
                }

                // ── document → journal link ──
                // Skip when documentId is null (batch processes like FX revaluation have
                // no single source document; storing 0 or null would create a meaningless link).
                if ($request->documentId !== null) {
                    JournalEntryLink::create([
                        'source_document_type' => $request->documentType,
                        'source_document_id'   => $request->documentId,
                        'journal_entry_id'     => $entry->id,
                        'relation_type'        => 'post',
                    ]);
                }

                // ── audit log ──
                $auditLog = PostingAuditLog::create([
                    'document_type'    => $request->documentType,
                    'document_id'      => $request->documentId,
                    'journal_entry_id' => $entry->id,
                    'action'           => 'post',
                    'actor_user_id'    => $userId,
                    'approval_user_id' => $request->approvedBy,
                    'reason_code'      => $request->overrideReason,
                    'payload_snapshot' => [
                        'description' => $request->description,
                        'posting_date' => $request->postingDate,
                        'lines_count' => count($resolvedLines),
                        'total_debit' => array_sum(array_column($resolvedLines, 'debit')),
                        'metadata' => $request->metadata,
                    ],
                ]);

                return PostingResult::success($entry->load('lines'), $auditLog->id);
            });
        } catch (\Exception $e) {
            Log::error("PostingEngine::post failed", [
                'document_type' => $request->documentType,
                'document_id'   => $request->documentId,
                'error'         => $e->getMessage(),
            ]);

            return PostingResult::failure("Posting failed: " . $e->getMessage());
        }
    }

    /**
     * Reverse a previously posted journal entry.
     *
     * Validates the reversal date against lock + fiscal controls and blocks
     * self-approval before creating the mirror entry.
     */
    public function reverse(ReversalRequest $request): PostingResult
    {
        // ── Self-approval guard ──────────────────────────────────────────────
        $actorId    = TenantUserHelper::requireTenantUserId($request->actorUserId);
        $approvedBy = $request->approvalUserId;

        if ($approvedBy !== null && $approvedBy === $actorId) {
            return PostingResult::failure(
                'Self-approval of reversals is not permitted. A different approver is required.'
            );
        }

        // ── Reversal date: lock-date validation ──────────────────────────────
        $lockResult = $this->lockDateValidator->validateTransactionDate(
            'journal_entries',
            $request->reversalDate,
        );

        if ($lockResult['is_locked']) {
            // Workstream 2 (2026-04-15): Enforce real permission checks for lock overrides
            if ($request->approvalUserId) {
                // Validate that the approver has permission to override lock dates
                if (!$this->lockDateValidator->canOverrideLockDate($request->approvalUserId)) {
                    return PostingResult::failure(
                        "Approver (ID: {$request->approvalUserId}) does not have permission to override lock dates. " .
                        "Required: accounting.lock_date_override permission or admin role."
                    );
                }

                // Validate that the actor also has permission
                if (!$this->lockDateValidator->canOverrideLockDate($actorId)) {
                    return PostingResult::failure(
                        "User (ID: {$actorId}) does not have permission to reverse in locked periods. " .
                        "Required: accounting.lock_date_override permission or admin role."
                    );
                }

                $this->lockDateValidator->logOverride(
                    $actorId,
                    'journal_entry',
                    $request->journalEntryId,
                    $request->reversalDate,
                    $request->reason,
                    $request->approvalUserId,
                );
            } else {
                return PostingResult::failure($lockResult['message']);
            }
        }

        // ── Reversal date: fiscal-period validation ──────────────────────────
        $fiscalResult = $this->fiscalValidator->validateDate($request->reversalDate);
        if (!$fiscalResult['allowed']) {
            return PostingResult::failure($fiscalResult['reason']);
        }

        try {
            return DB::transaction(function () use ($request, $actorId, $approvedBy) {
                $original = JournalEntry::with('lines')->findOrFail($request->journalEntryId);

                if ($original->status !== 'posted') {
                    throw new \Exception('Can only reverse posted entries');
                }

                if ($original->is_reversal) {
                    throw new \Exception('Cannot reverse a reversal entry');
                }

                if ($original->reversalEntry()->exists()) {
                    throw new \Exception('Entry has already been reversed');
                }

                // Perform reversal inline (JournalEntry::reverse() is deprecated)
                $reversalEntry = JournalEntry::create([
                    'journal_id'        => $original->journal_id,
                    'entry_number'    => JournalEntry::generateEntryNumber(),
                    'entry_date'       => $request->reversalDate,
                    'type'             => $original->type,
                    'reference'        => $original->reference,
                    'description'     => 'REVERSAL: ' . ($request->reason ?? $original->description),
                    'status'           => 'posted',
                    'posted_at'        => now(),
                    'posted_by'        => $actorId,
                    'created_by'       => $actorId,
                    'reversed_entry_id' => $original->id,
                    'is_reversal'       => true,
                    'legal_entity_id'  => $original->legal_entity_id,
                    'entity_book_id'   => $original->entity_book_id,
                ]);

                // Create reversed lines (swap debits and credits)
                foreach ($original->lines as $line) {
                    $reversalEntry->lines()->create([
                        'account_id'      => $line->account_id,
                        'debit'           => $line->credit,
                        'credit'          => $line->debit,
                        'description'    => 'REVERSAL: ' . $line->description,
                        'reference'      => $line->reference,
                        'currency_code'  => $line->currency_code,
                        'original_amount' => $line->original_amount ? -$line->original_amount : null,
                        'exchange_rate'   => $line->exchange_rate,
                        'department_id'  => $line->department_id,
                        'project_id'     => $line->project_id,
                        'location_id'    => $line->location_id,
                    ]);
                }

                // Find the original document link to propagate source info
                $originalLink = JournalEntryLink::where('journal_entry_id', $original->id)
                    ->where('relation_type', 'post')
                    ->first();

                if ($originalLink) {
                    $originalLink->update([
                        'reversal_journal_entry_id' => $reversalEntry->id,
                    ]);

                    JournalEntryLink::create([
                        'source_document_type'      => $originalLink->source_document_type,
                        'source_document_id'        => $originalLink->source_document_id,
                        'journal_entry_id'          => $reversalEntry->id,
                        'original_journal_entry_id' => $original->id,
                        'relation_type'             => 'reversal',
                    ]);
                }

                // Audit log
                $auditLog = PostingAuditLog::create([
                    'document_type'    => $originalLink?->source_document_type ?? 'journal_entry',
                    'document_id'      => $originalLink?->source_document_id ?? $original->id,
                    'journal_entry_id' => $reversalEntry->id,
                    'action'           => 'reverse',
                    'actor_user_id'    => $actorId,
                    'approval_user_id' => $approvedBy,
                    'reason_code'      => $request->reasonCode ?? $request->reason,
                    'payload_snapshot' => [
                        'original_entry_id'     => $original->id,
                        'original_entry_number' => $original->entry_number,
                        'reversal_date'         => $request->reversalDate,
                    ],
                ]);

                return PostingResult::success($reversalEntry->load('lines'), $auditLog->id);
            });
        } catch (\Exception $e) {
            Log::error('PostingEngine::reverse failed', [
                'journal_entry_id' => $request->journalEntryId,
                'error'            => $e->getMessage(),
            ]);

            return PostingResult::failure('Reversal failed: ' . $e->getMessage());
        }
    }

    /**
     * Preview the journal effect without committing.
     */
    public function preview(PostingRequest $request): array
    {
        $resolvedLines = $this->resolveLines($request->lines, $request->documentType);

        $totalDebit  = array_sum(array_column($resolvedLines, 'debit'));
        $totalCredit = array_sum(array_column($resolvedLines, 'credit'));

        return [
            'lines'        => $resolvedLines,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced'  => abs($totalDebit - $totalCredit) < 0.01,
            'posting_date' => $request->postingDate,
            'description'  => $request->description,
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
                if (!$this->lockDateValidator->canOverrideLockDate($request->approvedBy)) {
                    throw new \Exception(
                        "Approver (ID: {$request->approvedBy}) does not have permission to override lock dates. " .
                        "Required: accounting.lock_date_override permission or admin role."
                    );
                }

                // Validate that the actor also has permission (can't post to locked period without permission)
                if (!$this->lockDateValidator->canOverrideLockDate($actorId)) {
                    throw new \Exception(
                        "User (ID: {$actorId}) does not have permission to post to locked periods. " .
                        "Required: accounting.lock_date_override permission or admin role."
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
     * @param PostingRequestLine[] $lines
     */
    private function resolveLines(array $lines, string $documentType): array
    {
        $resolved = [];

        foreach ($lines as $line) {
            // Skip zero-value lines
            if (abs($line->debit) < 0.001 && abs($line->credit) < 0.001) {
                continue;
            }

            // Resolve account: explicit ID wins, otherwise map by key
            $accountId = $line->accountId
                ?? ($this->roleResolver->isRoleBased()
                    ? $this->roleResolver->resolveId($line->accountMappingKey, $documentType)
                    : $this->accountMapper->resolveId($line->accountMappingKey, $documentType));

            $resolved[] = [
                'account_id'      => $accountId,
                'debit'           => round($line->debit, 2),
                'credit'          => round($line->credit, 2),
                'description'     => $line->description,
                'reference'       => $line->reference,
                'department_id'   => $line->departmentId,
                'project_id'     => $line->projectId,
                'location_id'    => $line->locationId,
                'currency_code'  => $line->currencyCode ?? 'USD',
                'original_amount' => $line->originalAmount,
                'exchange_rate'  => $line->exchangeRate ?? 1.0,
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
            'invoice', 'credit_note'       => 'sales',
            'bill', 'vendor_credit_note'   => 'purchases',
            'payment', 'receipt', 'bill_payment' => 'payments',
            'pos_order'                    => 'sales',
            'ocr_document'                 => 'purchases',
            'web_order'                    => 'sales',
            'fx_revaluation', 'depreciation', 'asset_disposal', 'bank_adjustment' => 'journal_entries',
            default                        => 'journal_entries',
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
}
