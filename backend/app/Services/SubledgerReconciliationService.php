<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;

class SubledgerReconciliationService
{
    public function reconcileAR(): array
    {
        $subledgerTotal = DB::table('invoices')
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->sum('balance');

        $arAccounts = ChartOfAccount::where('type', 'asset')
            ->where(function ($q) {
                $q->where('name', 'ILIKE', '%account%receivable%')
                  ->orWhere('name', 'ILIKE', '%trade%receivable%')
                  ->orWhere('code', 'LIKE', '11%');
            })
            ->pluck('id');

        $glTotal = 0;
        if ($arAccounts->isNotEmpty()) {
            $glTotal = DB::table('journal_entry_lines')
                ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
                ->whereIn('journal_entry_lines.account_id', $arAccounts)
                ->where('journal_entries.status', 'posted')
                ->selectRaw('SUM(journal_entry_lines.debit - journal_entry_lines.credit) as balance')
                ->value('balance') ?? 0;
        }

        $difference = round($subledgerTotal - $glTotal, 2);

        return [
            'type' => 'AR',
            'subledger_total' => round($subledgerTotal, 2),
            'gl_total' => round($glTotal, 2),
            'difference' => $difference,
            'reconciled' => abs($difference) < 0.01,
            'ar_accounts' => $arAccounts->toArray(),
            'reconciled_at' => now()->toISOString(),
        ];
    }

    public function reconcileAP(): array
    {
        $subledgerTotal = DB::table('bills')
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->sum('balance');

        $apAccounts = ChartOfAccount::where('type', 'liability')
            ->where(function ($q) {
                $q->where('name', 'ILIKE', '%account%payable%')
                  ->orWhere('name', 'ILIKE', '%trade%payable%')
                  ->orWhere('code', 'LIKE', '21%');
            })
            ->pluck('id');

        $glTotal = 0;
        if ($apAccounts->isNotEmpty()) {
            $glTotal = DB::table('journal_entry_lines')
                ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
                ->whereIn('journal_entry_lines.account_id', $apAccounts)
                ->where('journal_entries.status', 'posted')
                ->selectRaw('SUM(journal_entry_lines.credit - journal_entry_lines.debit) as balance')
                ->value('balance') ?? 0;
        }

        $difference = round($subledgerTotal - $glTotal, 2);

        return [
            'type' => 'AP',
            'subledger_total' => round($subledgerTotal, 2),
            'gl_total' => round($glTotal, 2),
            'difference' => $difference,
            'reconciled' => abs($difference) < 0.01,
            'ap_accounts' => $apAccounts->toArray(),
            'reconciled_at' => now()->toISOString(),
        ];
    }

    public function reconcileAll(): array
    {
        return [
            'ar' => $this->reconcileAR(),
            'ap' => $this->reconcileAP(),
        ];
    }
}
