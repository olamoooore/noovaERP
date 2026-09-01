<?php

namespace App\Accounting\Services;

use App\Accounting\DTOs\ReversalRequest as ReversalRequestDTO;
use App\Accounting\Enums\ReasonCode;
use App\Accounting\Enums\ReversalBasis;
use App\Accounting\Enums\ReversalMode;
use App\Accounting\Enums\StateDimension;
use App\Accounting\Events\ReversalPosted;
use App\Models\AutoReversalInstruction;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\JournalEntryLink;
use App\Models\PostingAuditLog;
use App\Models\ReversalComponent;
use App\Models\ReversalRelationship;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ACC-002 Reversal / Correction Engine.
 * ADR-005 §15.3 — the only accounting component permitted to create authoritative
 * inverse/correcting postings against an existing posted ledger result.
 *
 * Key invariants:
 * - Zero-or-complete transaction boundary
 * - Original records remain immutable
 * - Component-level partial reversal lineage
 * - Duplicate/over-reversal protection
 * - ReversalRelationship + component lineage commit atomically with posting
 */
class ReversalCorrectionEngine
{
    public function __construct(
        private readonly StateTransitionService $transitionService,
    ) {}

    /**
     * Execute a full reversal.
     * ADR-005 §15.3.1/§15.3.3 — creates a new balanced posting whose effect is
     * the inverse of the targeted reversible amount.
     */
    public function fullReversal(ReversalRequestDTO $request): ReversalResult
    {
        return DB::transaction(function () use ($request) {
            $original = JournalEntry::with('lines')->findOrFail($request->journalEntryId);

            // ── Eligibility gates ──────────────────────────────
            $eligibility = $this->evaluateReversalEligibility($original, ReversalMode::FULL_REVERSAL, null);
            if (! $eligibility->isAllowed()) {
                return ReversalResult::blocked($eligibility->reasonCode, $eligibility->reason);
            }

            // ── Create reversal relationship ───────────────────
            $relationship = ReversalRelationship::create([
                'tenant_id' => $request->tenantId ?? $original->tenant_id ?? null,
                'original_journal_entry_id' => $original->id,
                'original_ledger_entry_id' => $original->id,
                'reversal_mode' => ReversalMode::FULL_REVERSAL->value,
                'reversal_basis' => ReversalBasis::FULL_ORIGINAL_POSTING->value,
                'reversal_reason_code' => $request->reasonCode,
                'reversal_reason_text' => $request->reason,
                'full_or_partial' => 'FULL',
                'reversed_amount_summary' => $original->lines->sum(fn ($l) => max((float) $l->debit, (float) $l->credit)),
                'component_count' => $original->lines->count(),
                'requested_by' => $request->actorUserId,
                'posted_at' => now(),
                'correlation_id' => $request->correlationId,
            ]);

            // ── Create reversal posting (mirror lines) ─────────
            $reversalEntry = $this->createFullReversalPosting($original, $request);

            // ── Create component lineage for each line ──────────
            $this->createFullComponentLineage($relationship, $original, $reversalEntry);

            // ── Update reversal relationship with reversal journal ─
            $relationship->update([
                'reversal_journal_entry_id' => $reversalEntry->id,
                'reversal_ledger_entry_id' => $reversalEntry->id,
            ]);

            // ── Link through JournalEntryLink ───────────────────
            $this->createReversalLink($original, $reversalEntry, $request);

            // ── Audit log ──────────────────────────────────────
            $this->createAuditLog($original, $reversalEntry, $request, 'full_reversal');

            // ── State transition ────────────────────────────────
            $this->transitionService->recordTransition(
                aggregateType: 'JournalEntry',
                aggregateId: (string) $original->id,
                dimension: StateDimension::JOURNAL_POSTING,
                fromState: 'POSTED',
                toState: 'REVERSED',
                context: [
                    'actor_id' => (string) $request->actorUserId,
                    'reason_code' => $request->reasonCode,
                    'correlation_id' => $request->correlationId,
                    'metadata' => ['reversal_journal_entry_id' => $reversalEntry->id],
                ],
            );

            // ── Fire event ─────────────────────────────────────
            event(new ReversalPosted(
                reversalRelationshipId: $relationship->id,
                originalJournalEntryId: $original->id,
                reversalJournalEntryId: $reversalEntry->id,
                reversalMode: ReversalMode::FULL_REVERSAL->value,
                reversedAmount: $relationship->reversed_amount_summary,
                correlationId: $request->correlationId,
                tenantId: $request->tenantId,
            ));

            return ReversalResult::success($relationship, $reversalEntry);
        });
    }

    /**
     * Execute a partial reversal.
     * ADR-005 §15.3.5 — must identify or deterministically derive the exact
     * original components being reversed. Header-level amounts are summaries only.
     */
    public function partialReversal(
        ReversalRequestDTO $request,
        array $lineAmounts,
    ): ReversalResult {
        return DB::transaction(function () use ($request, $lineAmounts) {
            $original = JournalEntry::with('lines')->findOrFail($request->journalEntryId);

            // ── Eligibility gates ──────────────────────────────
            $eligibility = $this->evaluateReversalEligibility($original, ReversalMode::PARTIAL_REVERSAL, $lineAmounts);
            if (! $eligibility->isAllowed()) {
                return ReversalResult::blocked($eligibility->reasonCode, $eligibility->reason);
            }

            // ── Validate component amounts don't exceed remaining reversible ──
            $validationError = $this->validatePartialAmounts($original, $lineAmounts);
            if ($validationError) {
                return ReversalResult::blocked(ReasonCode::OVER_REVERSAL_NOT_PERMITTED, $validationError);
            }

            // ── Create reversal relationship ───────────────────
            $totalReversed = array_sum(array_column($lineAmounts, 'amount'));
            $remainingAfter = $this->calculateRemainingAfterPartial($original, $lineAmounts);

            $relationship = ReversalRelationship::create([
                'tenant_id' => $request->tenantId ?? $original->tenant_id ?? null,
                'original_journal_entry_id' => $original->id,
                'original_ledger_entry_id' => $original->id,
                'reversal_mode' => ReversalMode::PARTIAL_REVERSAL->value,
                'reversal_basis' => ReversalBasis::SOURCE_LINES->value,
                'reversal_reason_code' => $request->reasonCode,
                'reversal_reason_text' => $request->reason,
                'full_or_partial' => 'PARTIAL',
                'reversed_amount_summary' => $totalReversed,
                'remaining_reversible_amount_summary' => $remainingAfter,
                'component_count' => count($lineAmounts),
                'requested_by' => $request->actorUserId,
                'posted_at' => now(),
                'correlation_id' => $request->correlationId,
            ]);

            // ── Create reversal posting ────────────────────────
            $reversalEntry = $this->createPartialReversalPosting($original, $request, $lineAmounts);

            // ── Create component lineage ───────────────────────
            $this->createPartialComponentLineage($relationship, $original, $reversalEntry, $lineAmounts);

            // ── Update reversal journal reference ───────────────
            $relationship->update([
                'reversal_journal_entry_id' => $reversalEntry->id,
                'reversal_ledger_entry_id' => $reversalEntry->id,
            ]);

            // ── Link ──────────────────────────────────────────
            $this->createReversalLink($original, $reversalEntry, $request);

            // ── Audit log ──────────────────────────────────────
            $this->createAuditLog($original, $reversalEntry, $request, 'partial_reversal');

            // ── State transition ────────────────────────────────
            // ADR-005 §11: posted entries remain in POSTED; the partial
            // reversal is recorded on the REVERSAL dimension, not the
            // JOURNAL_POSTING dimension.
            $this->transitionService->recordTransition(
                aggregateType: 'JournalEntry',
                aggregateId: (string) $original->id,
                dimension: StateDimension::REVERSAL,
                fromState: 'NONE',
                toState: 'PARTIALLY_REVERSED',
                context: [
                    'actor_id' => (string) $request->actorUserId,
                    'reason_code' => $request->reasonCode,
                    'correlation_id' => $request->correlationId,
                    'metadata' => [
                        'reversal_journal_entry_id' => $reversalEntry->id,
                        'reversal_relationship_id' => (string) $relationship->id,
                        'reversed_amount' => $totalReversed,
                        'remaining_reversible' => $remainingAfter,
                    ],
                ],
            );

            // ── Fire event ─────────────────────────────────────
            event(new ReversalPosted(
                reversalRelationshipId: $relationship->id,
                originalJournalEntryId: $original->id,
                reversalJournalEntryId: $reversalEntry->id,
                reversalMode: ReversalMode::PARTIAL_REVERSAL->value,
                reversedAmount: $totalReversed,
                correlationId: $request->correlationId,
                tenantId: $request->tenantId,
            ));

            return ReversalResult::success($relationship, $reversalEntry);
        });
    }

    /**
     * Schedule an auto-reversal.
     * ADR-005 §15.3.7 — the engine must re-evaluate all mandatory gates at execution time.
     */
    public function scheduleAutoReversal(
        int $journalEntryId,
        string $scheduledDate,
        string $reasonCode,
        ?string $reasonText = null,
        ?string $createdBy = null,
        ?string $correlationId = null,
    ): AutoReversalInstruction {
        $instruction = AutoReversalInstruction::create([
            'tenant_id' => auth()->user()?->tenant_id,
            'original_journal_entry_id' => $journalEntryId,
            'scheduled_reversal_date' => $scheduledDate,
            'reversal_reason_code' => $reasonCode,
            'reversal_reason_text' => $reasonText,
            'execution_state' => AutoReversalInstruction::STATE_SCHEDULED,
            'created_by' => $createdBy ?? (string) auth()->id(),
        ]);

        event(new AutoReversalScheduled(
            instructionId: $instruction->id,
            originalJournalEntryId: $journalEntryId,
            scheduledDate: $scheduledDate,
            reasonCode: $reasonCode,
            correlationId: $correlationId,
        ));

        return $instruction;
    }

    /**
     * Execute due auto-reversals.
     * Re-evaluates all mandatory gates at execution time.
     */
    public function executeDueAutoReversals(): array
    {
        $due = AutoReversalInstruction::dueForExecution()->get();
        $results = [];

        foreach ($due as $instruction) {
            $instruction->markExecuting();

            try {
                $original = JournalEntry::with('lines')->findOrFail($instruction->original_journal_entry_id);

                $request = new ReversalRequestDTO(
                    journalEntryId: $original->id,
                    reversalDate: now()->toDateString(),
                    reason: $instruction->reversal_reason_text ?? 'Auto-reversal: '.$instruction->reversal_reason_code,
                    reasonCode: $instruction->reversal_reason_code,
                    tenantId: $instruction->tenant_id,
                    correlationId: $instruction->correlation_id ?? null,
                );

                $result = $this->fullReversal($request);

                if ($result->success) {
                    $instruction->markCompleted($result->journalEntry->id);

                    event(new AutoReversalPosted(
                        instructionId: $instruction->id,
                        originalJournalEntryId: $original->id,
                        reversalJournalEntryId: $result->journalEntry->id,
                        reversedAmount: $result->relationship->reversed_amount_summary,
                        correlationId: $instruction->correlation_id,
                        tenantId: $instruction->tenant_id,
                    ));
                } else {
                    $instruction->markFailed($result->reason ?? 'Unknown failure');
                }

                $results[] = ['instruction_id' => $instruction->id, 'success' => $result->success];
            } catch (\Exception $e) {
                $instruction->markFailed($e->getMessage());
                $results[] = ['instruction_id' => $instruction->id, 'success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    // ══════════════════════════════════════════════════════
    // PRIVATE HELPER METHODS
    // ══════════════════════════════════════════════════════

    private function evaluateReversalEligibility(
        JournalEntry $original,
        ReversalMode $mode,
        ?array $lineAmounts,
    ): ReversalEligibilityResult {
        // Gate 1: Original posting exists and is authoritative
        if ($original->status !== 'posted') {
            return ReversalEligibilityResult::blocked(ReasonCode::ORIGINAL_NOT_REVERSIBLE, 'Only posted entries can be reversed.');
        }

        // Gate 2: Cannot reverse a reversal
        if ($original->is_reversal) {
            return ReversalEligibilityResult::blocked(ReasonCode::ORIGINAL_NOT_REVERSIBLE, 'Cannot reverse a reversal entry.');
        }

        // Gate 3: Check if already fully reversed
        $existingFullReversal = ReversalRelationship::forOriginal($original->id)
            ->fullReversals()
            ->exists();
        if ($existingFullReversal) {
            return ReversalEligibilityResult::blocked(ReasonCode::REVERSAL_ALREADY_COMPLETED, 'This entry has already been fully reversed.');
        }

        // Gate 4: For partial reversal, verify remaining reversible amount
        if ($mode === ReversalMode::PARTIAL_REVERSAL && $lineAmounts) {
            $remaining = $this->calculateTotalRemainingReversible($original);
            $requestedTotal = array_sum(array_column($lineAmounts, 'amount'));
            if ($requestedTotal > $remaining + 0.01) {
                return ReversalEligibilityResult::blocked(ReasonCode::OVER_REVERSAL_NOT_PERMITTED, 'Requested reversal amount exceeds remaining reversible amount.');
            }
        }

        return ReversalEligibilityResult::allowed();
    }

    private function calculateTotalRemainingReversible(JournalEntry $original): float
    {
        $totalOriginal = $original->lines->sum(fn ($l) => max((float) $l->debit, (float) $l->credit));
        $totalReversed = ReversalComponent::whereHas('reversalRelationship', function ($q) use ($original) {
            $q->forOriginal($original->id);
        })->sum('reversal_amount');

        return $totalOriginal - abs($totalReversed);
    }

    private function calculateRemainingAfterPartial(JournalEntry $original, array $lineAmounts): float
    {
        $remaining = $this->calculateTotalRemainingReversible($original);
        $requestedTotal = array_sum(array_column($lineAmounts, 'amount'));

        return max(0, $remaining - $requestedTotal);
    }

    private function validatePartialAmounts(JournalEntry $original, array $lineAmounts): ?string
    {
        foreach ($lineAmounts as $lineAmount) {
            $lineId = $lineAmount['line_id'] ?? null;
            $amount = $lineAmount['amount'] ?? 0;

            if (! $lineId || $amount <= 0) {
                return 'Each partial reversal line must have a valid line_id and positive amount.';
            }

            $line = JournalEntryLine::find($lineId);
            if (! $line || $line->journal_entry_id !== $original->id) {
                return "Line {$lineId} does not belong to the original entry.";
            }

            $remaining = ReversalComponent::calculateRemaining($line);
            if ($amount > $remaining + 0.01) {
                return "Amount {$amount} exceeds remaining reversible amount {$remaining} for line {$lineId}.";
            }
        }

        return null;
    }

    private function createReversalPosting(
        JournalEntry $original,
        ReversalRequestDTO $request,
        string $descriptionPrefix,
    ): JournalEntry {
        $reversalEntry = JournalEntry::create([
            'journal_id' => $original->journal_id,
            'entry_number' => JournalEntry::generateEntryNumber(),
            'entry_date' => $request->reversalDate,
            'type' => $original->type,
            'reference' => $original->reference,
            'description' => $descriptionPrefix.': '.($request->reason ?? $original->description),
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => $request->actorUserId,
            'created_by' => $request->actorUserId,
            'reversed_entry_id' => $original->id,
            'is_reversal' => true,
            // Reversal postings must not appear in financial reports as an
            // independent active version — their purpose is to cancel the
            // original entry. If the original document is reposted, only
            // the new active-version entry should be counted.
            'is_active_version' => false,
            'lifecycle_status' => 'reversed',
            'legal_entity_id' => $original->legal_entity_id,
            'entity_book_id' => $original->entity_book_id,
        ]);

        return $reversalEntry;
    }

    /**
     * Full reversal posting — header plus mirrored lines.
     *
     * Every original line is copied with debit/credit swapped so the reversal
     * is a balanced posting whose net effect against the original is exactly
     * zero on every account (Revenue, AR, Tax, …). Without the mirrored lines
     * the reversal would be header-only and the ledger would keep the original
     * effect — a silent one-sided correction.
     */
    private function createFullReversalPosting(
        JournalEntry $original,
        ReversalRequestDTO $request,
    ): JournalEntry {
        $reversalEntry = $this->createReversalPosting($original, $request, 'FULL REVERSAL');

        foreach ($original->lines as $line) {
            $reversalEntry->lines()->create([
                'account_id' => $line->account_id,
                'debit' => $line->credit,
                'credit' => $line->debit,
                'description' => 'FULL REVERSAL: '.$line->description,
                'reference' => $line->reference,
                'currency_code' => $line->currency_code,
                'original_amount' => $line->original_amount !== null ? -$line->original_amount : null,
                'exchange_rate' => $line->exchange_rate,
                'department_id' => $line->department_id,
                'project_id' => $line->project_id,
                'location_id' => $line->location_id,
            ]);
        }

        return $reversalEntry;
    }

    private function createPartialReversalPosting(
        JournalEntry $original,
        ReversalRequestDTO $request,
        array $lineAmounts,
    ): JournalEntry {
        $reversalEntry = $this->createReversalPosting($original, $request, 'PARTIAL REVERSAL');

        // Create reversed lines only for the specified amounts
        foreach ($lineAmounts as $lineAmount) {
            $originalLine = JournalEntryLine::findOrFail($lineAmount['line_id']);
            $amount = $lineAmount['amount'];

            // Swap debit/credit based on original direction
            $isDebit = (float) $originalLine->debit > 0;

            $reversalEntry->lines()->create([
                'account_id' => $originalLine->account_id,
                'debit' => $isDebit ? 0 : $amount,
                'credit' => $isDebit ? $amount : 0,
                'description' => 'PARTIAL REVERSAL: '.$originalLine->description,
                'reference' => $originalLine->reference,
                'currency_code' => $originalLine->currency_code,
                'original_amount' => $isDebit ? -$amount : $amount,
                'exchange_rate' => $originalLine->exchange_rate,
                'department_id' => $originalLine->department_id,
                'project_id' => $originalLine->project_id,
                'location_id' => $originalLine->location_id,
            ]);
        }

        return $reversalEntry;
    }

    private function createFullComponentLineage(
        ReversalRelationship $relationship,
        JournalEntry $original,
        JournalEntry $reversalEntry,
    ): void {
        foreach ($original->lines as $index => $line) {
            $reversalLine = $reversalEntry->lines->get($index);
            $originalAmount = max((float) $line->debit, (float) $line->credit);

            ReversalComponent::create([
                'reversal_relationship_id' => $relationship->id,
                'original_ledger_entry_line_id' => $line->id,
                'reversal_amount' => $originalAmount,
                'transaction_currency_amount' => $originalAmount,
                'base_currency_amount' => $originalAmount,
                'component_remaining_reversible_amount' => 0,
                'account_id' => $line->account_id,
                'original_debit' => $line->debit,
                'original_credit' => $line->credit,
                'reversed_debit' => $reversalLine?->debit ?? 0,
                'reversed_credit' => $reversalLine?->credit ?? 0,
            ]);
        }
    }

    private function createPartialComponentLineage(
        ReversalRelationship $relationship,
        JournalEntry $original,
        JournalEntry $reversalEntry,
        array $lineAmounts,
    ): void {
        foreach ($lineAmounts as $index => $lineAmount) {
            $originalLine = JournalEntryLine::find($lineAmount['line_id']);
            $reversalLine = $reversalEntry->lines->get($index);
            $amount = $lineAmount['amount'];
            $remaining = ReversalComponent::calculateRemaining($originalLine) - $amount;

            ReversalComponent::create([
                'reversal_relationship_id' => $relationship->id,
                'original_ledger_entry_line_id' => $originalLine->id,
                'reversal_amount' => $amount,
                'transaction_currency_amount' => $amount,
                'base_currency_amount' => $amount,
                'component_remaining_reversible_amount' => max(0, $remaining),
                'account_id' => $originalLine->account_id,
                'original_debit' => $originalLine->debit,
                'original_credit' => $originalLine->credit,
                'reversed_debit' => $reversalLine?->debit ?? 0,
                'reversed_credit' => $reversalLine?->credit ?? 0,
            ]);
        }
    }

    private function createReversalLink(
        JournalEntry $original,
        JournalEntry $reversalEntry,
        ReversalRequestDTO $request,
    ): void {
        $originalLink = JournalEntryLink::where('journal_entry_id', $original->id)
            ->where('relation_type', 'post')
            ->first();

        $sourceDocumentType = $originalLink?->source_document_type ?? 'journal_entry';
        $sourceDocumentId = $originalLink?->source_document_id ?? $original->id;

        if ($originalLink) {
            $originalLink->update(['reversal_journal_entry_id' => $reversalEntry->id]);
        }

        // Drop any pre-existing reversal link for the same source document.
        // The composite unique index `journal_entry_links_source_post_unique`
        // on (source_document_type, source_document_id, relation_type) only
        // allows ONE reversal link per source document. If a prior reversal
        // JE exists for this source (e.g. from an earlier cancel→reset cycle
        // on the same invoice), the previous reversal JE is by construction
        // already marked is_active_version=false and inactive, so its link
        // is stale and safe to remove before we register the new one.
        JournalEntryLink::where('source_document_type', $sourceDocumentType)
            ->where('source_document_id', $sourceDocumentId)
            ->where('relation_type', 'reversal')
            ->delete();

        JournalEntryLink::create([
            'source_document_type' => $sourceDocumentType,
            'source_document_id' => $sourceDocumentId,
            'journal_entry_id' => $reversalEntry->id,
            'original_journal_entry_id' => $original->id,
            'relation_type' => 'reversal',
        ]);
    }

    private function createAuditLog(
        JournalEntry $original,
        JournalEntry $reversalEntry,
        ReversalRequestDTO $request,
        string $action,
    ): void {
        $originalLink = JournalEntryLink::where('journal_entry_id', $original->id)
            ->where('relation_type', 'post')
            ->first();

        PostingAuditLog::create([
            'document_type' => $originalLink?->source_document_type ?? 'journal_entry',
            'document_id' => $originalLink?->source_document_id ?? $original->id,
            'journal_entry_id' => $reversalEntry->id,
            'action' => $action,
            'actor_user_id' => $request->actorUserId,
            'reason_code' => $request->reasonCode ?? $request->reason,
            'payload_snapshot' => [
                'original_entry_id' => $original->id,
                'original_entry_number' => $original->entry_number,
                'reversal_date' => $request->reversalDate,
                'mode' => $action,
            ],
        ]);
    }
}

// ══════════════════════════════════════════════════════
// RESULT CLASSES
// ══════════════════════════════════════════════════════

class ReversalResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?ReversalRelationship $relationship = null,
        public readonly ?JournalEntry $journalEntry = null,
        public readonly ?ReasonCode $reasonCode = null,
        public readonly ?string $reason = null,
    ) {}

    public static function success(ReversalRelationship $relationship, JournalEntry $journalEntry): self
    {
        return new self(success: true, relationship: $relationship, journalEntry: $journalEntry);
    }

    public static function blocked(?ReasonCode $reasonCode, ?string $reason): self
    {
        return new self(success: false, reasonCode: $reasonCode, reason: $reason);
    }
}

class ReversalEligibilityResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?ReasonCode $reasonCode = null,
        public readonly ?string $reason = null,
    ) {}

    public static function allowed(): self
    {
        return new self(allowed: true);
    }

    public static function blocked(ReasonCode $reasonCode, string $reason): self
    {
        return new self(allowed: false, reasonCode: $reasonCode, reason: $reason);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }
}
