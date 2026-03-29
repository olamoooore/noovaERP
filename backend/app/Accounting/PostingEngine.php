<?php

namespace App\Accounting;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\PostingResult;
use App\Accounting\Services\AccountMappingService;
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
        private readonly LockDateValidationService $lockDateValidator,
        private readonly FiscalPeriodValidator $fiscalValidator,
    ) {}

    /**
     * Post a business document to the ledger.
     */
    public function post(PostingRequest $request): PostingResult
    {
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
                $userId = $request->userId ?? TenantUserHelper::getTenantUserId();

                $entry = JournalEntry::create([
                    'journal_id'   => $request->journalId,
                    'entry_number' => $this->generateEntryNumber(),
                    'entry_date'   => $request->postingDate,
                    'type'         => $request->documentType,
                    'reference'    => $request->reference,
                    'description'  => $request->description,
                    'status'       => 'posted',
                    'posted_at'    => now(),
                    'posted_by'    => $userId,
                    'created_by'   => $userId,
                ]);

                foreach ($resolvedLines as $line) {
                    $entry->lines()->create($line);
                }

                // ── document → journal link ──
                JournalEntryLink::create([
                    'source_document_type' => $request->documentType,
                    'source_document_id'   => $request->documentId,
                    'journal_entry_id'     => $entry->id,
                    'relation_type'        => 'post',
                ]);

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
     */
    public function reverse(int $journalEntryId, string $reason, ?int $approvedBy = null): PostingResult
    {
        try {
            return DB::transaction(function () use ($journalEntryId, $reason, $approvedBy) {
                $original = JournalEntry::with('lines')->findOrFail($journalEntryId);

                // Delegate to model's existing reverse() method
                $reversal = $original->reverse($reason);

                $userId = TenantUserHelper::getTenantUserId();

                // Find the original document link to propagate source info
                $originalLink = JournalEntryLink::where('journal_entry_id', $original->id)
                    ->where('relation_type', 'post')
                    ->first();

                if ($originalLink) {
                    // Update original link with reversal reference
                    $originalLink->update([
                        'reversal_journal_entry_id' => $reversal->id,
                    ]);

                    // Create reverse link
                    JournalEntryLink::create([
                        'source_document_type'       => $originalLink->source_document_type,
                        'source_document_id'         => $originalLink->source_document_id,
                        'journal_entry_id'           => $reversal->id,
                        'original_journal_entry_id'  => $original->id,
                        'relation_type'              => 'reversal',
                    ]);
                }

                // Audit log
                $auditLog = PostingAuditLog::create([
                    'document_type'    => $originalLink?->source_document_type ?? 'journal_entry',
                    'document_id'      => $originalLink?->source_document_id ?? $original->id,
                    'journal_entry_id' => $reversal->id,
                    'action'           => 'reverse',
                    'actor_user_id'    => $userId,
                    'approval_user_id' => $approvedBy,
                    'reason_code'      => $reason,
                    'payload_snapshot' => [
                        'original_entry_id'     => $original->id,
                        'original_entry_number' => $original->entry_number,
                    ],
                ]);

                return PostingResult::success($reversal->load('lines'), $auditLog->id);
            });
        } catch (\Exception $e) {
            Log::error("PostingEngine::reverse failed", [
                'journal_entry_id' => $journalEntryId,
                'error'            => $e->getMessage(),
            ]);

            return PostingResult::failure("Reversal failed: " . $e->getMessage());
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
            // Check for override
            if ($request->overrideReason && $request->approvedBy) {
                $this->lockDateValidator->logOverride(
                    $request->userId ?? TenantUserHelper::getTenantUserId(),
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
                ?? $this->accountMapper->resolveId($line->accountMappingKey, $documentType);

            $resolved[] = [
                'account_id'      => $accountId,
                'debit'           => round($line->debit, 2),
                'credit'          => round($line->credit, 2),
                'description'     => $line->description,
                'reference'       => $line->reference,
                'department_id'   => $line->departmentId,
                'project_id'     => $line->projectId,
                'location_id'    => $line->locationId,
                'currency_code'  => $line->currencyCode,
                'original_amount' => $line->originalAmount,
                'exchange_rate'  => $line->exchangeRate,
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

    private function generateEntryNumber(): string
    {
        $latest = JournalEntry::orderBy('id', 'desc')->first();
        $number = $latest ? (int) substr($latest->entry_number, -4) + 1 : 1;
        return 'JE-' . date('Y') . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }
}
