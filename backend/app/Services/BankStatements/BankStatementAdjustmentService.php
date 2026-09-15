<?php

namespace App\Services\BankStatements;

use App\Models\BankStatementAdjustment;
use App\Models\BankStatementTransaction;
use App\Services\BankReconciliationService;
use Illuminate\Support\Facades\DB;

class BankStatementAdjustmentService
{
    public function __construct(
        private BankReconciliationService $reconciliation,
        private BankStatementMatchingService $matching,
        private BankStatementAuditService $audit,
    ) {}

    public function propose(string $transactionId, array $data, int $actorId): BankStatementAdjustment
    {
        return DB::transaction(function () use ($transactionId, $data, $actorId) {
            $transaction = BankStatementTransaction::lockForUpdate()->findOrFail($transactionId);
            if (! in_array($transaction->status, ['unmatched', 'suggested', 'partially_matched'], true)) {
                throw new \DomainException('This statement transaction is not available for adjustment.');
            }
            $amount = (float) $data['amount'];
            if ($amount * (float) $transaction->residual_amount <= 0 || abs($amount) > abs((float) $transaction->residual_amount)) {
                throw new \DomainException('Adjustment amount must agree with and not exceed the statement residual.');
            }
            $existing = BankStatementAdjustment::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing;
            }
            $adjustment = BankStatementAdjustment::create(['bank_statement_transaction_id' => $transaction->id, 'contra_account_id' => $data['contra_account_id'], 'amount' => $amount, 'description' => $data['description'], 'reason' => $data['reason'], 'status' => 'proposed', 'requested_by' => $actorId, 'idempotency_key' => $data['idempotency_key']]);
            $this->audit->record('adjusting_entry_proposed', ['actor_id' => $actorId, 'bank_account_id' => $transaction->bank_account_id, 'import_id' => $transaction->import_id, 'statement_transaction_id' => $transaction->id, 'reason' => $data['reason'], 'after_state' => ['adjustment_id' => $adjustment->id, 'amount' => $amount]]);

            return $adjustment;
        });
    }

    public function approve(string $adjustmentId, int $actorId): BankStatementAdjustment
    {
        return DB::transaction(function () use ($adjustmentId, $actorId) {
            $adjustment = BankStatementAdjustment::with('transaction')->lockForUpdate()->findOrFail($adjustmentId);
            if ($adjustment->status === 'posted') {
                return $adjustment;
            }
            if ($adjustment->status !== 'proposed') {
                throw new \DomainException('Only proposed adjustments can be approved.');
            }
            if ((int) $adjustment->requested_by === $actorId) {
                throw new \DomainException('Maker-checker control requires a different user to approve this adjustment.');
            }
            $transaction = BankStatementTransaction::lockForUpdate()->findOrFail($adjustment->bank_statement_transaction_id);
            $bankLine = $this->reconciliation->addAdjustingEntry(['bank_account_id' => $transaction->bank_account_id, 'date' => $transaction->transaction_date->format('Y-m-d'), 'description' => $adjustment->description, 'amount' => abs((float) $adjustment->amount), 'type' => (float) $adjustment->amount > 0 ? 'debit' : 'credit', 'reference' => $transaction->bank_reference, 'contra_account_id' => $adjustment->contra_account_id]);
            $this->matching->accept(['bank_statement_transaction_id' => $transaction->id, 'journal_entry_line_id' => $bankLine->id, 'matched_amount' => (float) $adjustment->amount, 'idempotency_key' => 'adjustment:'.$adjustment->id, 'review_reason' => $adjustment->reason], $actorId);
            $adjustment->update(['status' => 'posted', 'approved_by' => $actorId, 'approved_at' => now(), 'journal_entry_line_id' => $bankLine->id]);
            $this->audit->record('adjusting_entry_posted', ['actor_id' => $actorId, 'bank_account_id' => $transaction->bank_account_id, 'import_id' => $transaction->import_id, 'statement_transaction_id' => $transaction->id, 'reason' => $adjustment->reason, 'after_state' => ['adjustment_id' => $adjustment->id, 'journal_entry_line_id' => $bankLine->id]]);

            return $adjustment->fresh();
        });
    }
}
