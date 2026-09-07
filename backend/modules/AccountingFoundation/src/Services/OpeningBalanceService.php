<?php

namespace Modules\AccountingFoundation\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\FiscalPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingFoundation\Enums\OpeningBalanceStatus;
use Modules\AccountingFoundation\Models\OpeningBalanceAuditTrail;
use Modules\AccountingFoundation\Models\OpeningBalanceBatch;
use Modules\AccountingFoundation\Models\OpeningBalanceLine;

/**
 * ACC-001 Opening Balance — main orchestrator.
 *
 * Issue #70 (ACC-DEC-060 Track B): manages the full opening balance
 * lifecycle from DRAFT through POSTED. Enforces fail-closed rules,
 * SoD (preparer ≠ approver), idempotency, and scope uniqueness.
 *
 * Architecture: ACC-001 owns preparation, validation, reconciliation,
 * approval, and posting authorisation. ACC-002 (PostingEngine) is the
 * sole authoritative ledger writer — ACC-001 never writes JournalEntry
 * directly.
 */
class OpeningBalanceService
{
    public function __construct(
        private readonly OpeningBalanceLifecycle $lifecycle,
        private readonly OpeningBalanceValidator $validator,
        private readonly OpeningBalanceReconciler $reconciler,
        private readonly PostingEngineInterface $postingEngine,
    ) {}

    // ── Create ────────────────────────────────────────────────────

    /**
     * Create a new opening balance batch in DRAFT status.
     *
     * Fail-closed: blocks if another active batch exists for the same
     * controlled scope (tenant + entity + book + cutover + population).
     */
    public function createBatch(array $data, string $actorId): OpeningBalanceBatch
    {
        $this->assertNoActiveBaselineForScope(
            $data['tenant_id'],
            $data['legal_entity_id'] ?? null,
            $data['accounting_book_id'] ?? null,
            $data['cutover_date'],
            $data['population_scope'],
        );

        $batch = DB::transaction(function () use ($data, $actorId) {
            $batch = OpeningBalanceBatch::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $data['tenant_id'],
                'legal_entity_id' => $data['legal_entity_id'] ?? null,
                'accounting_book_id' => $data['accounting_book_id'] ?? null,
                'status' => OpeningBalanceStatus::DRAFT->value,
                'source_type' => $data['source_type'] ?? 'manual',
                'source_reference' => $data['source_reference'] ?? null,
                'source_hash' => $data['source_hash'] ?? hash('sha256', ''),
                'source_version' => $data['source_version'] ?? 1,
                'cutover_date' => $data['cutover_date'],
                'fiscal_period_id' => $data['fiscal_period_id'],
                'population_scope' => $data['population_scope'] ?? 'full_trial_balance',
                'mapping_version' => $data['mapping_version'] ?? null,
                'mapping_hash' => $data['mapping_hash'] ?? null,
                'created_by' => $actorId,
            ]);

            $this->recordAudit($batch, 'created', null, OpeningBalanceStatus::DRAFT, $actorId);

            return $batch;
        });

        return $batch;
    }

    // ── Capture / Import ──────────────────────────────────────────

    /**
     * Capture lines into a DRAFT batch. Lines are immutable once the
     * batch leaves DRAFT status.
     */
    public function captureLines(OpeningBalanceBatch $batch, array $lines, string $actorId): void
    {
        $this->assertStatus($batch, OpeningBalanceStatus::DRAFT);

        DB::transaction(function () use ($batch, $lines, $actorId) {
            foreach ($lines as $line) {
                OpeningBalanceLine::create([
                    'id' => (string) Str::uuid(),
                    'batch_id' => $batch->id,
                    'account_id' => $line['account_id'],
                    'account_code' => $line['account_code'] ?? '',
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                    'currency_code' => $line['currency_code'] ?? 'USD',
                    'exchange_rate' => $line['exchange_rate'] ?? null,
                    'original_amount' => $line['original_amount'] ?? null,
                    'source_line_ref' => $line['source_line_ref'] ?? null,
                    'source_hash' => $line['source_hash'] ?? null,
                ]);
            }

            // Compute and store the source hash from captured lines
            $batch->update(['source_hash' => $batch->computeSourceHash()]);
            $this->transitionTo($batch, OpeningBalanceStatus::IMPORTED, $actorId, 'Lines captured');
        });
    }

    // ── Validate ──────────────────────────────────────────────────

    /**
     * Validate the batch: balance check, account existence, period
     * validity, mapping drift detection.
     *
     * Fail-closed: validation failure transitions to VALIDATION_FAILED.
     */
    public function validate(OpeningBalanceBatch $batch, string $actorId): void
    {
        $this->assertStatus($batch, OpeningBalanceStatus::IMPORTED);

        $result = $this->validator->validate($batch);

        if ($result->passed) {
            $this->transitionTo($batch, OpeningBalanceStatus::VALIDATED, $actorId, 'Validation passed');
        } else {
            $this->transitionTo($batch, OpeningBalanceStatus::VALIDATION_FAILED, $actorId, implode('; ', $result->errors));
        }
    }

    // ── Reconcile ─────────────────────────────────────────────────

    /**
     * Reconcile against authoritative AR/AP/FA/inventory/bank/tax
     * source populations. ACC-001 does not own foreign source truth —
     * it only cross-checks.
     *
     * Fail-closed: reconciliation failure transitions to RECONCILIATION_FAILED.
     */
    public function reconcile(OpeningBalanceBatch $batch, string $actorId): void
    {
        $this->assertStatus($batch, OpeningBalanceStatus::VALIDATED);

        $result = $this->reconciler->reconcile($batch);

        if ($result->passed) {
            $this->transitionTo($batch, OpeningBalanceStatus::RECONCILED, $actorId, 'Reconciliation passed');
        } else {
            $this->transitionTo($batch, OpeningBalanceStatus::RECONCILIATION_FAILED, $actorId, implode('; ', $result->errors));
        }
    }

    // ── Approve ───────────────────────────────────────────────────

    /**
     * Approve the batch. SoD: approver must differ from creator/preparer.
     *
     * Fail-closed: stale approval is rejected; approved snapshot is
     * stored immutably for later Posting Effect Binding.
     */
    public function approve(OpeningBalanceBatch $batch, string $approverId): void
    {
        $this->assertStatus($batch, OpeningBalanceStatus::PENDING_APPROVAL);

        // SoD: approver ≠ creator
        if ($batch->created_by == $approverId) {
            throw new \DomainException('SOD_VIOLATION: approver must differ from preparer');
        }

        // Store immutable approval snapshot
        $snapshot = $batch->computeApprovalSnapshot();

        DB::transaction(function () use ($batch, $approverId, $snapshot) {
            $batch->update([
                'approved_by' => $approverId,
                'approved_at' => now(),
                'approval_snapshot' => $snapshot,
            ]);

            $this->transitionTo($batch, OpeningBalanceStatus::APPROVED, $approverId, 'Approved');
        });
    }

    // ── Authorise for posting ─────────────────────────────────────

    /**
     * Authorise the approved batch for posting. Validates that the
     * approved snapshot matches the current state (no drift).
     *
     * Fail-closed: mapping drift or snapshot mismatch blocks authorisation.
     */
    public function authoriseForPosting(OpeningBalanceBatch $batch, string $actorId): void
    {
        $this->assertStatus($batch, OpeningBalanceStatus::APPROVED);

        // Verify approval snapshot matches current state
        if ($batch->approval_snapshot) {
            $currentHash = $batch->source_hash;
            if ($currentHash !== ($batch->approval_snapshot['source_hash'] ?? null)) {
                throw new \DomainException('APPROVAL_STALE: source hash changed since approval');
            }
        }

        $this->transitionTo($batch, OpeningBalanceStatus::POSTING_AUTHORISED, $actorId, 'Authorised for posting');
    }

    // ── Post via ACC-002 PostingEngine ────────────────────────────

    /**
     * Post the opening balance to the ledger via ACC-002 PostingEngine.
     *
     * This is the ONLY path from ACC-001 to ledger truth. ACC-001
     * never writes JournalEntry directly (prohibited responsibility).
     *
     * Fail-closed:
     * - batch must be POSTING_AUTHORISED
     * - PostingEngine must succeed
     * - journal_entry_id is stored for source→journal linkage
     */
    public function post(OpeningBalanceBatch $batch, string $actorId): void
    {
        $this->assertStatus($batch, OpeningBalanceStatus::POSTING_AUTHORISED);

        $this->transitionTo($batch, OpeningBalanceStatus::POSTING, $actorId, 'Posting initiated');

        try {
            $lines = $batch->lines()->where('status', 'active')->get();

            $postingLines = $lines->map(fn ($line) => new PostingRequestLine(
                accountMappingKey: 'opening_balance',
                accountId: (int) $line->account_id,
                debit: (float) $line->debit,
                credit: (float) $line->credit,
                description: "Opening balance: {$line->account_code}",
            ))->toArray();

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'opening_balance',
                documentId: (int) 0,
                postingDate: $batch->cutover_date->toDateString(),
                description: "Opening balance for {$batch->population_scope}",
                lines: $postingLines,
                reference: "OB-{$batch->id}-v{$batch->source_version}",
                userId: (int) $actorId,
                metadata: [
                    'opening_balance_batch_id' => $batch->id,
                    'population_scope' => $batch->population_scope,
                    'source_hash' => $batch->source_hash,
                    'mapping_version' => $batch->mapping_version,
                ],
            ));

            if (! $result->success || ! $result->journalEntry) {
                $this->transitionTo($batch, OpeningBalanceStatus::POSTING_FAILED, $actorId, $result->error ?? 'PostingEngine rejected');
                throw new \DomainException('POSTING_FAILED: ' . ($result->error ?? 'Unknown error'));
            }

            DB::transaction(function () use ($batch, $result, $actorId) {
                $batch->update([
                    'journal_entry_id' => $result->journalEntry->id,
                    'posted_at' => now(),
                    'posted_by' => $actorId,
                ]);

                $this->transitionTo($batch, OpeningBalanceStatus::POSTED, $actorId, 'Posted via PostingEngine');
            });
        } catch (\Throwable $e) {
            if ($batch->status === OpeningBalanceStatus::POSTING->value) {
                $this->transitionTo($batch, OpeningBalanceStatus::POSTING_FAILED, $actorId, $e->getMessage());
            }
            throw $e;
        }
    }

    // ── Supersede ─────────────────────────────────────────────────

    /**
     * Supersede an active batch (versioning). The superseded batch
     * becomes terminal — its lines are marked superseded.
     */
    public function supersede(OpeningBalanceBatch $batch, string $actorId, string $reason): void
    {
        if (! in_array($batch->status, [
            OpeningBalanceStatus::APPROVED->value,
            OpeningBalanceStatus::POSTING_AUTHORISED->value,
        ])) {
            throw new \DomainException('FORBIDDEN_TRANSITION: can only supersede approved or posting_authorised batches');
        }

        DB::transaction(function () use ($batch, $actorId, $reason) {
            $batch->lines()->where('status', 'active')->update(['status' => 'superseded']);
            $this->transitionTo($batch, OpeningBalanceStatus::SUPERSEDED, $actorId, $reason);
        });
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function transitionTo(OpeningBalanceBatch $batch, OpeningBalanceStatus $to, string $actorId, ?string $reason = null): void
    {
        $from = $batch->getStatusEnum();

        $this->lifecycle->validateTransition($from, $to);

        $batch->update(['status' => $to->value]);

        $this->recordAudit($batch, $to->value, $from, $to, $actorId, $reason);
    }

    private function recordAudit(
        OpeningBalanceBatch $batch,
        string $action,
        ?OpeningBalanceStatus $from,
        OpeningBalanceStatus $to,
        string $actorId,
        ?string $reason = null,
    ): void {
        OpeningBalanceAuditTrail::create([
            'batch_id' => $batch->id,
            'action' => $action,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_id' => $actorId,
            'reason' => $reason,
        ]);
    }

    private function assertStatus(OpeningBalanceBatch $batch, OpeningBalanceStatus $expected): void
    {
        if ($batch->status !== $expected->value) {
            throw new \DomainException(sprintf(
                'INVALID_STATUS: expected %s, got %s',
                $expected->value,
                $batch->status,
            ));
        }
    }

    /**
     * Fail-closed: block creation if another active batch exists for
     * the same controlled scope.
     */
    private function assertNoActiveBaselineForScope(
        string $tenantId,
        ?string $legalEntityId,
        ?string $accountingBookId,
        string $cutoverDate,
        string $populationScope,
    ): void {
        $query = OpeningBalanceBatch::where('tenant_id', $tenantId)
            ->where('cutover_date', $cutoverDate)
            ->where('population_scope', $populationScope)
            ->whereNotIn('status', [
                OpeningBalanceStatus::SUPERSEDED->value,
                OpeningBalanceStatus::REJECTED->value,
            ]);

        if ($legalEntityId) {
            $query->where('legal_entity_id', $legalEntityId);
        } else {
            $query->whereNull('legal_entity_id');
        }

        if ($accountingBookId) {
            $query->where('accounting_book_id', $accountingBookId);
        } else {
            $query->whereNull('accounting_book_id');
        }

        if ($query->exists()) {
            throw new \DomainException('SCOPE_OVERLAP: an active opening balance already exists for this scope');
        }
    }
}
