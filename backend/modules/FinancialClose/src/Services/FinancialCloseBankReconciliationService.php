<?php

namespace Modules\FinancialClose\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialCloseBankReconciliationService
{
    public function cutoffForClosePeriod(string $periodId, array $metadata = []): Carbon
    {
        $configuredCutoff = data_get($metadata, 'bank_reconciliation_cutoff_date');
        if (is_string($configuredCutoff) && $configuredCutoff !== '') {
            try {
                return Carbon::parse($configuredCutoff)->endOfDay();
            } catch (\Throwable) {
                // Use the close period when malformed metadata is encountered.
            }
        }

        if (preg_match('/(\d{4})-(\d{2})/', $periodId, $matches)) {
            return Carbon::createFromDate((int) $matches[1], (int) $matches[2], 1)->endOfMonth();
        }

        return now()->endOfMonth();
    }

    public function unresolvedThrough(CarbonInterface $cutoff): array
    {
        $activeBanks = DB::table('bank_accounts')->where('is_active', true)->get(['id', 'gl_account_id'])->keyBy('id');
        if ($activeBanks->isEmpty()) {
            return $this->emptyResult('persisted_statement');
        }

        if (Schema::hasTable('bank_statement_imports') && Schema::hasTable('bank_statement_transactions')) {
            $adoptedBankIds = DB::table('bank_statement_imports')->whereIn('bank_account_id', $activeBanks->keys())->whereIn('status', ['imported', 'reversed'])->distinct()->pluck('bank_account_id');
            if ($adoptedBankIds->isNotEmpty()) {
                $transactions = DB::table('bank_statement_transactions as bst')
                    ->join('bank_statement_imports as bsi', 'bsi.id', '=', 'bst.import_id')
                    ->whereIn('bst.bank_account_id', $adoptedBankIds)
                    ->where('bsi.status', 'imported')
                    ->whereIn('bst.status', ['unmatched', 'suggested', 'partially_matched'])
                    ->whereDate('bst.transaction_date', '<=', $cutoff->toDateString())
                    ->orderBy('bst.transaction_date')->orderBy('bst.id')
                    ->get(['bst.id', 'bst.bank_account_id', 'bst.transaction_date', 'bst.description', 'bst.bank_reference', 'bst.customer_code', 'bst.residual_amount', 'bst.currency_code']);

                return $this->statementResult($transactions, $cutoff);
            }
        }

        return $this->legacyResult($activeBanks, $cutoff);
    }

    private function statementResult(Collection $transactions, CarbonInterface $cutoff): array
    {
        return [
            'mode' => 'persisted_statement',
            'cutoff_date' => $cutoff->toDateString(),
            'count' => $transactions->count(),
            'net_amount' => abs((float) $transactions->sum('residual_amount')),
            'total_residual_amount' => (float) $transactions->sum(fn ($transaction) => abs((float) $transaction->residual_amount)),
            'bank_account_ids' => $transactions->pluck('bank_account_id')->unique()->values()->all(),
            'source_refs' => $transactions->take(50)->map(fn ($transaction) => [
                'type' => 'bank_statement_transaction',
                'id' => (string) $transaction->id,
                'title' => $transaction->bank_reference ?: 'Statement transaction '.$transaction->transaction_date,
                'label' => $transaction->bank_reference ?: 'Statement transaction '.$transaction->transaction_date,
                'route' => '/accounting/core/reconciliation?account='.$transaction->bank_account_id.'&statement_transaction='.$transaction->id,
                'meta' => ['bank_account_id' => (string) $transaction->bank_account_id, 'date' => (string) $transaction->transaction_date, 'residual_amount' => number_format((float) $transaction->residual_amount, 2), 'currency_code' => $transaction->currency_code],
            ])->values()->all(),
        ];
    }

    private function legacyResult(Collection $activeBanks, CarbonInterface $cutoff): array
    {
        $lines = DB::table('journal_entry_lines as jel')->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->whereNull('jel.reconciliation_id')->whereIn('jel.account_id', $activeBanks->pluck('gl_account_id'))->where('je.status', 'posted')
            ->whereDate('je.entry_date', '<=', $cutoff->toDateString())->orderBy('je.entry_date')->orderBy('jel.id')
            ->get(['jel.id', 'jel.journal_entry_id', 'jel.account_id', 'jel.debit', 'jel.credit', 'jel.description', 'je.entry_date']);
        $bankByGl = $activeBanks->keyBy('gl_account_id');

        return [
            'mode' => 'legacy_gl_fallback', 'cutoff_date' => $cutoff->toDateString(), 'count' => $lines->count(),
            'net_amount' => abs((float) $lines->sum(fn ($line) => (float) $line->debit - (float) $line->credit)),
            'total_residual_amount' => (float) $lines->sum(fn ($line) => abs((float) $line->debit - (float) $line->credit)),
            'bank_account_ids' => $lines->map(fn ($line) => $bankByGl->get($line->account_id)?->id)->filter()->unique()->values()->all(),
            'source_refs' => $lines->take(50)->map(fn ($line) => ['type' => 'journal_entry', 'id' => (string) $line->journal_entry_id, 'title' => 'Bank GL line #'.$line->id, 'label' => 'Bank GL line #'.$line->id, 'route' => '/accounting/core/reconciliation?account='.$bankByGl->get($line->account_id)?->id, 'meta' => ['journal_entry_id' => (string) $line->journal_entry_id, 'date' => (string) $line->entry_date, 'amount' => number_format((float) $line->debit - (float) $line->credit, 2)]])->values()->all(),
        ];
    }

    private function emptyResult(string $mode): array
    {
        return ['mode' => $mode, 'count' => 0, 'net_amount' => 0.0, 'total_residual_amount' => 0.0, 'bank_account_ids' => [], 'source_refs' => []];
    }
}
