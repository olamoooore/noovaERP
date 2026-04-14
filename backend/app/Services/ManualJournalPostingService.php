<?php

namespace App\Services;

use App\Accounting\DTOs\PostingResult;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\PostingAuditLog;
use App\Services\FiscalPeriodValidator;
use App\Services\LockDateValidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * ManualJournalPostingService
 *
 * Posts a draft manual journal entry IN-PLACE — the existing row is transitioned
 * to 'posted' status rather than being deleted and recreated.
 *
 * This preserves audit continuity: every reference to the original draft ID
 * (audit logs, UI history, linked documents) remains valid after posting.
 */
class ManualJournalPostingService
{
    public function __construct(
        private readonly LockDateValidationService $lockDateValidator,
        private readonly FiscalPeriodValidator $fiscalValidator,
    ) {}

    /**
     * Post a draft journal entry in-place.
     *
     * @param  JournalEntry $entry          The draft entry to post (must be 'draft' status)
     * @param  int          $userId         The actor performing the post
     * @param  string|null  $overrideReason Non-null when posting to a locked period with approval
     * @param  int|null     $approvedBy     Approver ID for lock-date overrides (must differ from $userId)
     * @return PostingResult
     */
    public function post(
        JournalEntry $entry,
        int $userId,
        ?string $overrideReason = null,
        ?int $approvedBy = null,
    ): PostingResult {
        if ($entry->status === 'posted') {
            return PostingResult::failure('Journal entry is already posted.');
        }

        if ($entry->status !== 'draft') {
            return PostingResult::failure("Journal entry cannot be posted from status '{$entry->status}'.");
        }

        // ── 1. Lock-date validation ──────────────────────────────────────────
        $lockResult = $this->lockDateValidator->validateTransactionDate(
            'journal_entries',
            $entry->entry_date,
        );

        if ($lockResult['is_locked']) {
            if ($overrideReason && $approvedBy) {
                if ($approvedBy === $userId) {
                    return PostingResult::failure(
                        'Self-approval of lock-date overrides is not permitted. A different approver is required.'
                    );
                }
                $this->lockDateValidator->logOverride(
                    $userId,
                    'journal_entry',
                    $entry->id,
                    $entry->entry_date,
                    $overrideReason,
                    $approvedBy,
                );
            } else {
                return PostingResult::failure($lockResult['message']);
            }
        }

        // ── 2. Fiscal-period validation ──────────────────────────────────────
        $fiscalResult = $this->fiscalValidator->validateDate($entry->entry_date);
        if (!$fiscalResult['allowed']) {
            if ($overrideReason && $approvedBy) {
                if ($fiscalResult['period'] ?? null) {
                    $actor = \App\Models\User::find($userId);
                    if ($actor) {
                        $this->fiscalValidator->logOverride(
                            $fiscalResult['period'],
                            $actor,
                            'manual_journal_post',
                        );
                    }
                }
            } else {
                return PostingResult::failure($fiscalResult['reason']);
            }
        }

        // ── 3. Balance check ─────────────────────────────────────────────────
        $entry->loadMissing('lines');
        $totalDebit  = (float) $entry->lines->sum('debit');
        $totalCredit = (float) $entry->lines->sum('credit');

        if (abs($totalDebit - $totalCredit) > 0.01) {
            return PostingResult::failure(
                "Journal entry is unbalanced. Debit: {$totalDebit}, Credit: {$totalCredit}"
            );
        }

        // ── 4. Atomic transition ─────────────────────────────────────────────
        try {
            return DB::transaction(function () use ($entry, $userId, $overrideReason) {
                // Transition the EXISTING entry in-place — no delete, no new ID
                $entry->update([
                    'status'    => 'posted',
                    'posted_at' => now(),
                    'posted_by' => $userId,
                ]);

                // Create the document → journal link (source_document_id points to the SAME entry)
                JournalEntryLink::create([
                    'source_document_type' => 'manual',
                    'source_document_id'   => $entry->id,
                    'journal_entry_id'     => $entry->id,
                    'relation_type'        => 'post',
                ]);

                // Audit log
                $auditLog = PostingAuditLog::create([
                    'document_type'    => 'manual',
                    'document_id'      => $entry->id,
                    'journal_entry_id' => $entry->id,
                    'action'           => 'post',
                    'actor_user_id'    => $userId,
                    'reason_code'      => $overrideReason,
                ]);

                return PostingResult::success($entry->load('lines'), $auditLog->id);
            });
        } catch (\Exception $e) {
            Log::error('ManualJournalPostingService::post failed', [
                'entry_id' => $entry->id,
                'error'    => $e->getMessage(),
            ]);

            return PostingResult::failure('Posting failed: ' . $e->getMessage());
        }
    }
}
