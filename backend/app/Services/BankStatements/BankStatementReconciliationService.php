<?php

namespace App\Services\BankStatements;

use App\Models\BankReconciliation;
use App\Models\BankReconciliationReversal;
use App\Models\BankReconciliationScope;
use App\Models\BankStatementImport;
use App\Models\BankStatementMatch;
use App\Models\BankStatementOperation;
use App\Models\BankStatementTransaction;
use App\Models\FiscalPeriod;
use App\Models\JournalEntryLine;
use App\Models\ReconciliationItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BankStatementReconciliationService
{
    public function __construct(
        private CurrencyToleranceService $tolerances,
        private BankStatementAuditService $audit,
    ) {}

    public function validate(int $bankAccountId, array $importIds, int $actorId): array
    {
        $imports = BankStatementImport::query()->whereIn('id', $importIds)->orderBy('statement_end_date')->get();
        $blockers = [];
        if ($imports->count() !== count(array_unique($importIds))) {
            $blockers[] = ['code' => 'IMPORT_NOT_FOUND', 'message' => 'One or more selected imports are unavailable.'];
        }
        if ($imports->contains(fn ($import) => (int) $import->bank_account_id !== $bankAccountId)) {
            $blockers[] = ['code' => 'BANK_ACCOUNT_MISMATCH', 'message' => 'Every import must belong to the selected bank account.'];
        }
        if ($imports->contains(fn ($import) => $import->status !== 'imported')) {
            $blockers[] = ['code' => 'IMPORT_NOT_READY', 'message' => 'Every selected import must have imported status.'];
        }
        $currencies = $imports->pluck('currency_code')->filter()->unique();
        if ($currencies->count() !== 1) {
            $blockers[] = ['code' => 'CURRENCY_MISMATCH', 'message' => 'Every selected import must use the same currency.'];
        }

        $currency = (string) ($currencies->first() ?? '');
        $tolerance = $currency !== '' ? (float) $this->tolerances->for($currency) : 0.01;
        $transactions = BankStatementTransaction::query()->whereIn('import_id', $imports->pluck('id'))->where('status', '!=', 'reversed')->get();
        $includedTransactions = $transactions->where('status', '!=', 'excluded');
        $opening = (float) ($imports->first()?->opening_balance ?? 0);
        $closing = (float) ($imports->last()?->closing_balance ?? 0);
        $statementMovements = (float) $includedTransactions->sum('amount');
        $sourceControlDifference = $closing - ($opening + $statementMovements);
        if (abs($sourceControlDifference) > $tolerance) {
            $blockers[] = ['code' => 'SOURCE_CONTROL_DIFFERENCE', 'message' => 'Opening balance plus statement movements does not equal the source closing balance.'];
        }

        $unmatched = $includedTransactions->filter(fn ($transaction) => abs((float) $transaction->residual_amount) > $tolerance);
        if ($unmatched->isNotEmpty()) {
            $blockers[] = ['code' => 'UNMATCHED_STATEMENT_TRANSACTIONS', 'message' => $unmatched->count().' statement transaction(s) still require matching.'];
        }

        $acceptedMatches = BankStatementMatch::query()->whereIn('bank_statement_transaction_id', $includedTransactions->pluck('id'))->where('status', 'accepted')->get();
        $matchedMovement = (float) $acceptedMatches->sum('matched_amount');
        $matchedGlBalance = $opening + $matchedMovement;
        $difference = $closing - $matchedGlBalance;
        if (abs($difference) > $tolerance) {
            $blockers[] = ['code' => 'GL_CONTROL_DIFFERENCE', 'message' => 'Accepted GL matches do not reconcile to the statement closing balance.'];
        }

        $previous = BankReconciliation::query()->where('bank_account_id', $bankAccountId)->where('status', 'completed')->orderByDesc('statement_date')->first();
        $continuityDifference = $previous ? $opening - (float) $previous->statement_balance : 0.0;
        if ($previous && abs($continuityDifference) > $tolerance) {
            $blockers[] = ['code' => 'OPENING_BALANCE_DISCONTINUITY', 'message' => 'Opening balance does not agree with the prior completed closing balance.'];
        }
        $statementEndDate = $imports->max('statement_end_date');
        $fiscalPeriod = $statementEndDate ? FiscalPeriod::getPeriodForDate($statementEndDate) : null;
        if (! $fiscalPeriod) {
            $blockers[] = ['code' => 'FISCAL_PERIOD_MISSING', 'message' => 'No fiscal period covers the statement ending date.'];
        } elseif ($fiscalPeriod->status !== 'open') {
            $blockers[] = ['code' => 'FISCAL_PERIOD_NOT_OPEN', 'message' => 'The fiscal period covering the statement ending date is not open.'];
        }

        $result = [
            'valid' => $blockers === [], 'blockers' => $blockers, 'bank_account_id' => $bankAccountId,
            'import_ids' => $imports->pluck('id')->values(), 'currency_code' => $currency, 'currency_tolerance' => $tolerance,
            'statement_start_date' => $imports->pluck('statement_start_date')->filter()->min()?->format('Y-m-d') ?? $transactions->min('transaction_date')?->format('Y-m-d'),
            'statement_end_date' => $statementEndDate?->format('Y-m-d'),
            'opening_balance' => $opening, 'deposits' => (float) $includedTransactions->where('amount', '>', 0)->sum('amount'),
            'withdrawals' => (float) $includedTransactions->where('amount', '<', 0)->sum('amount'),
            'statement_movements' => $statementMovements, 'statement_closing_balance' => $closing,
            'source_control_difference' => $sourceControlDifference, 'matched_gl_balance' => $matchedGlBalance,
            'unmatched_statement_count' => $unmatched->count(), 'unmatched_statement_amount' => (float) $unmatched->sum('residual_amount'),
            'excluded_count' => $transactions->where('status', 'excluded')->count(), 'continuity_difference' => $continuityDifference,
            'difference' => $difference,
        ];
        $this->audit->record('reconciliation_validated', ['actor_id' => $actorId, 'bank_account_id' => $bankAccountId, 'metadata' => ['import_ids' => $importIds], 'after_state' => ['valid' => $result['valid'], 'blocker_codes' => collect($blockers)->pluck('code'), 'difference' => $difference]]);

        return $result;
    }

    public function complete(int $bankAccountId, array $importIds, string $idempotencyKey, int $actorId): BankReconciliation
    {
        return DB::transaction(function () use ($bankAccountId, $importIds, $idempotencyKey, $actorId) {
            $payloadHash = hash('sha256', json_encode(['bank_account_id' => $bankAccountId, 'import_ids' => array_values($importIds)], JSON_THROW_ON_ERROR));
            $operation = BankStatementOperation::where('operation', 'reconciliation_complete')->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($operation) {
                if (! hash_equals($operation->payload_hash, $payloadHash)) {
                    throw new MatchConflict('Idempotency key was already used with different reconciliation inputs.');
                }
                if ($operation->status === 'completed') {
                    return BankReconciliation::findOrFail($operation->response_id);
                }
            }
            $operation ??= BankStatementOperation::create(['operation' => 'reconciliation_complete', 'idempotency_key' => $idempotencyKey, 'payload_hash' => $payloadHash, 'actor_id' => $actorId, 'status' => 'processing', 'correlation_id' => (string) Str::uuid()]);
            BankStatementImport::whereIn('id', $importIds)->orderBy('id')->lockForUpdate()->get();
            $transactions = BankStatementTransaction::whereIn('import_id', $importIds)->orderBy('id')->lockForUpdate()->get();
            $matches = BankStatementMatch::whereIn('bank_statement_transaction_id', $transactions->pluck('id'))->where('status', 'accepted')->orderBy('id')->lockForUpdate()->get();
            $glLines = JournalEntryLine::whereIn('id', $matches->pluck('journal_entry_line_id'))->orderBy('id')->lockForUpdate()->get();
            $validation = $this->validate($bankAccountId, $importIds, $actorId);
            if (! $validation['valid']) {
                throw new \DomainException('Statement reconciliation is blocked: '.collect($validation['blockers'])->pluck('message')->implode(' '));
            }
            if ($glLines->contains(fn ($line) => $line->reconciliation_id !== null)) {
                throw new MatchConflict('A selected GL line was reconciled by another operation.');
            }
            $previous = BankReconciliation::where('bank_account_id', $bankAccountId)->where('status', 'completed')->orderByDesc('statement_date')->first();
            $scope = BankReconciliationScope::create(['bank_account_id' => $bankAccountId, 'previous_statement_end_date' => $previous?->statement_date, 'statement_start_date' => $validation['statement_start_date'], 'statement_end_date' => $validation['statement_end_date'], 'import_ids' => array_values($importIds), 'opening_balance' => $validation['opening_balance'], 'closing_balance' => $validation['statement_closing_balance'], 'currency_code' => $validation['currency_code'], 'currency_tolerance' => $validation['currency_tolerance'], 'status' => 'completing', 'created_by' => $actorId]);
            $reconciliation = BankReconciliation::create(['bank_account_id' => $bankAccountId, 'statement_date' => $validation['statement_end_date'], 'statement_balance' => $validation['statement_closing_balance'], 'opening_balance' => $validation['opening_balance'], 'closing_balance' => $validation['statement_closing_balance'], 'difference' => $validation['difference'], 'status' => 'completed', 'reconciled_by' => $actorId, 'reconciled_at' => now()]);
            foreach ($matches->groupBy('journal_entry_line_id') as $journalLineId => $lineMatches) {
                $representative = $lineMatches->first();
                // Issue #841 (BK9): reconciliation_items carries a unique
                // (journal_entry_line_id), and a line staged by an auto-match
                // accept already owns a draft-scoped row — adopt it instead of
                // violating the uniqueness the "reconciled only once" control
                // exists for.
                ReconciliationItem::updateOrCreate(
                    ['journal_entry_line_id' => $journalLineId],
                    [
                        'reconciliation_id' => $reconciliation->id,
                        'bank_statement_transaction_id' => $representative->bank_statement_transaction_id,
                        'bank_statement_match_id' => $representative->id,
                        'reversed_at' => null,
                    ]
                );
            }
            JournalEntryLine::whereIn('id', $glLines->pluck('id'))->update(['reconciliation_id' => $reconciliation->id, 'reconciled' => true, 'reconciliation_date' => $validation['statement_end_date']]);
            BankStatementMatch::whereIn('id', $matches->pluck('id'))->update(['reconciliation_id' => $reconciliation->id]);
            $scope->update(['reconciliation_id' => $reconciliation->id, 'status' => 'completed', 'approved_by' => $actorId, 'approved_at' => now()]);
            $operation->update(['status' => 'completed', 'response_type' => BankReconciliation::class, 'response_id' => $reconciliation->id, 'response' => ['reconciliation_id' => $reconciliation->id]]);
            $this->audit->record('reconciliation_completed', ['actor_id' => $actorId, 'bank_account_id' => $bankAccountId, 'reconciliation_id' => $reconciliation->id, 'metadata' => ['scope_id' => $scope->id, 'import_ids' => $importIds], 'after_state' => ['difference' => $validation['difference']]], $operation->correlation_id);

            return $reconciliation;
        });
    }

    public function requestReversal(int $reconciliationId, string $reason, int $requesterId): BankReconciliationReversal
    {
        return DB::transaction(function () use ($reconciliationId, $reason, $requesterId) {
            $reconciliation = BankReconciliation::lockForUpdate()->findOrFail($reconciliationId);
            if (BankReconciliationReversal::where('reconciliation_id', $reconciliationId)->exists()) {
                throw new MatchConflict('This reconciliation already has a reversal request.');
            }
            $reversal = BankReconciliationReversal::create(['reconciliation_id' => $reconciliationId, 'reason' => $reason, 'status' => 'pending', 'requested_by' => $requesterId, 'requested_at' => now(), 'snapshot' => []]);
            $this->audit->record('reconciliation_reversal_requested', ['actor_id' => $requesterId, 'bank_account_id' => $reconciliation->bank_account_id, 'reconciliation_id' => $reconciliationId, 'reason' => $reason, 'after_state' => ['reversal_id' => $reversal->id, 'status' => 'pending']]);

            return $reversal;
        });
    }

    public function approveReversal(string $reversalId, int $approverId): BankReconciliationReversal
    {
        return DB::transaction(function () use ($reversalId, $approverId) {
            $reversal = BankReconciliationReversal::lockForUpdate()->findOrFail($reversalId);
            if ($reversal->status !== 'pending') {
                throw new MatchConflict('This reversal request is no longer pending.');
            }
            if ((int) $reversal->requested_by === $approverId) {
                throw new \DomainException('Maker-checker control requires a different user to approve reversal.');
            }
            $reconciliationId = (int) $reversal->reconciliation_id;
            $reconciliation = BankReconciliation::lockForUpdate()->findOrFail($reconciliationId);
            $items = ReconciliationItem::where('reconciliation_id', $reconciliationId)->whereNull('reversed_at')->orderBy('id')->lockForUpdate()->get();
            $matches = BankStatementMatch::where('reconciliation_id', $reconciliationId)->orderBy('id')->lockForUpdate()->get();
            JournalEntryLine::whereIn('id', $items->pluck('journal_entry_line_id'))->update(['reconciliation_id' => null, 'reconciled' => false, 'reconciliation_date' => null]);
            BankStatementMatch::whereIn('id', $matches->pluck('id'))->update(['reconciliation_id' => null]);
            ReconciliationItem::whereIn('id', $items->pluck('id'))->update(['reversed_at' => now()]);
            BankReconciliationScope::where('reconciliation_id', $reconciliationId)->update(['status' => 'reversed']);
            $reversal->update(['status' => 'reversed', 'approved_by' => $approverId, 'reversed_at' => now(), 'snapshot' => ['item_ids' => $items->pluck('id'), 'match_ids' => $matches->pluck('id')]]);
            $this->audit->record('reconciliation_reversed', ['actor_id' => $approverId, 'bank_account_id' => $reconciliation->bank_account_id, 'reconciliation_id' => $reconciliationId, 'reason' => $reversal->reason, 'after_state' => ['reversal_id' => $reversal->id]]);

            return $reversal->fresh();
        });
    }
}
