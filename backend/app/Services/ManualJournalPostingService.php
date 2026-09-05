<?php

namespace App\Services;

use App\Accounting\DTOs\PostingResult;
use App\Accounting\Services\ActionEligibilityEvaluator;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\PostingAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\TenantUserHelper;

/**
 * ManualJournalPostingService
 *
 * Posts a draft manual journal entry IN-PLACE — the existing row is transitioned
 * to 'posted' status rather than being deleted and recreated.
 *
 * This preserves audit continuity: every reference to the original draft ID
 * (audit logs, UI history, linked documents) remains valid after posting.
 *
 * ADR-005 §8, P0-2 / P0-3: prior to transitioning the entry to 'posted',
 * the action is evaluated through the platform Authority Gates
 * (PermissionGate, ControlAccountGate, AssuranceGate, EntitlementGate, etc.).
 * A BLOCK at any mandatory gate fails the post.
 */
class ManualJournalPostingService
{
    public function __construct(
        private readonly LockDateValidationService $lockDateValidator,
        private readonly FiscalPeriodValidator $fiscalValidator,
        private readonly ActionEligibilityEvaluator $eligibilityEvaluator,
    ) {}

    /**
     * Post a draft journal entry in-place.
     *
     * @param  JournalEntry  $entry  The draft entry to post (must be 'draft' status)
     * @param  int  $userId  The actor performing the post
     * @param  string|null  $overrideReason  Non-null when posting to a locked period with approval
     * @param  int|null  $approvedBy  Approver ID for lock-date overrides (must differ from $userId)
     * @param  int|null  $permissionActorId  Central authenticated user ID used by authority gates.
     *                                        Tenant and central user IDs are not guaranteed to match.
     */
    public function post(
        JournalEntry $entry,
        int $userId,
        ?string $overrideReason = null,
        ?int $approvedBy = null,
        ?int $permissionActorId = null,
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
            // Workstream 2 (2026-04-15): Enforce real permission checks for lock overrides
            if ($overrideReason && $approvedBy) {
                // Validate that the approver has permission to override lock dates
                if (! $this->lockDateValidator->canOverrideLockDate($approvedBy)) {
                    return PostingResult::failure(
                        "Approver (ID: {$approvedBy}) does not have permission to override lock dates. ".
                        'Required: accounting.lock_date_override permission or admin role.'
                    );
                }

                // Validate that the actor also has permission
                if (! $this->lockDateValidator->canOverrideLockDate($userId)) {
                    return PostingResult::failure(
                        "User (ID: {$userId}) does not have permission to post to locked periods. ".
                        'Required: accounting.lock_date_override permission or admin role.'
                    );
                }

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
        if (! $fiscalResult['allowed']) {
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
        $totalDebit = (float) $entry->lines->sum('debit');
        $totalCredit = (float) $entry->lines->sum('credit');

        if (abs($totalDebit - $totalCredit) > 0.01) {
            return PostingResult::failure(
                "Journal entry is unbalanced. Debit: {$totalDebit}, Credit: {$totalCredit}"
            );
        }

        // ── 4. Authority-gate evaluation (P0-2 / P0-3) ───────────────────────
        // P0-2: prevent manual journals from bypassing ControlAccountGate when
        // they target control accounts from non-whitelisted sources.
        // P0-3: enforce permission gate on every post. Entitlement gate is
        // deliberately not enforced here — manual journal posting is a core
        // accounting capability that is governed by permission, not by a
        // separately-purchased module entitlement (which would create an
        // operational blocker for tenants without an explicit entitlement
        // record).
        $eligibilityContext = [
            'tenant_id' => (string) ($entry->tenant_id ?? tenant('id')),
            'entity_id' => $entry->legal_entity_id ? (string) $entry->legal_entity_id : null,
            'actor_id' => (string) ($permissionActorId ?? $userId),
            'document_type' => 'manual_journal',
            'posting_date' => $entry->entry_date instanceof \DateTimeInterface
                ? $entry->entry_date->format('Y-m-d')
                : (string) $entry->entry_date,
            'total_amount' => $totalDebit,
            'correlation_id' => 'manual_journal_'.$entry->id,
            'required_permission' => 'accounting.post',
            'source_capability' => 'ACC-002',
            'posting_lines' => $entry->lines->map(fn ($l) => [
                'account_mapping_key' => 'manual',
                'account_id' => $l->account_id,
                'debit' => (float) $l->debit,
                'credit' => (float) $l->credit,
            ])->all(),
        ];

        $eligibility = $this->eligibilityEvaluator->evaluate(
            action: 'POST_JOURNAL',
            recordType: 'JournalEntry',
            recordId: (string) $entry->id,
            context: $eligibilityContext,
        );

        if ($eligibility->isBlocked()) {
            Log::warning('ManualJournalPostingService blocked by authority gate', [
                'entry_id' => $entry->id,
                'blocking_reason' => $eligibility->blockingReason?->value,
            ]);

            $details = $eligibility->blockingReason?->value ?? 'GATE_BLOCKED';

            return PostingResult::failure(
                'Posting blocked: '.$details.
                ' | gate_results='.json_encode(array_map(
                    fn ($r) => ['code' => $r->gateCode, 'result' => $r->result->value, 'reason' => $r->reasonCode],
                    $eligibility->gateResults ?? []
                ))
            );
        }

        if ($eligibility->hasError()) {
            Log::error('ManualJournalPostingService authority gate evaluation error', [
                'entry_id' => $entry->id,
            ]);

            return PostingResult::failure('Authority gate evaluation failed for manual journal post.');
        }

        // ── 5. Atomic transition ─────────────────────────────────────────────
        try {
            return DB::transaction(function () use ($entry, $userId, $overrideReason) {
                // Transition the EXISTING entry in-place — no delete, no new ID
                $entry->update([
                    'status' => 'posted',
                    'posted_at' => now(),
                    'posted_by' => $userId,
                    // lifecycle_status drives the correction policies
                    // (void/reverse require lifecycle 'posted'); without
                    // this sync, manually posted JEs were permanently
                    // uncorrectable through the sanctioned flows
                    // (caught 2026-09-04 during Acme COA cleanup).
                    'lifecycle_status' => 'posted',
                ]);

                // Create the document → journal link (source_document_id points to the SAME entry)
                JournalEntryLink::create([
                    'source_document_type' => 'manual',
                    'source_document_id' => $entry->id,
                    'journal_entry_id' => $entry->id,
                    'relation_type' => 'post',
                ]);

                // Audit log
                $auditLog = PostingAuditLog::create([
                    'document_type' => 'manual',
                    'document_id' => $entry->id,
                    'journal_entry_id' => $entry->id,
                    'action' => 'post',
                    'actor_user_id' => $userId,
                    'reason_code' => $overrideReason,
                ]);

                return PostingResult::success($entry->load('lines'), $auditLog->id);
            });
        } catch (\Exception $e) {
            Log::error('ManualJournalPostingService::post failed', [
                'entry_id' => $entry->id,
                'error' => $e->getMessage(),
            ]);

            return PostingResult::failure('Posting failed: '.$e->getMessage());
        }
    }
}
