<?php

namespace Modules\FinancialReporting\Services\Reports;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\FinancialReporting\DTOs\ReportData;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\BaseReportService;

class TrialBalanceService extends BaseReportService
{
    /**
     * Generate Trial Balance report
     *
     * A Trial Balance lists all accounts with their debit and credit balances
     * as of a specific date, verifying that total debits = total credits
     */
    public function generate(ReportFilter $filter): ReportData
    {
        $asOfDate = $filter->asOfDate ?? $filter->endDate ?? now();
        $accountType = $filter->accountType ?? null;

        // Debug logging
        \Log::info('TrialBalanceService::generate called', [
            'asOfDate' => $asOfDate->format('Y-m-d'),
            'accountType' => $accountType,
            'filter' => [
                'asOfDate' => $filter->asOfDate?->format('Y-m-d'),
                'endDate' => $filter->endDate?->format('Y-m-d'),
                'startDate' => $filter->startDate?->format('Y-m-d'),
            ],
        ]);

        // Check search_path
        $searchPath = \DB::select('SHOW search_path');
        \Log::info('Current search_path in TrialBalanceService', [
            'search_path' => $searchPath[0]->search_path ?? 'unknown',
        ]);

        // Get all account balances
        $accounts = $this->getTrialBalanceAccounts($asOfDate, $accountType, $filter);

        \Log::info('TrialBalanceService accounts retrieved', [
            'count' => $accounts->count(),
            'accounts' => $accounts->toArray(),
        ]);

        // Calculate totals
        $totalDebits = $accounts->sum('debit_balance');
        $totalCredits = $accounts->sum('credit_balance');
        $difference = abs($totalDebits - $totalCredits);
        $isBalanced = $difference < 0.01; // Allow for rounding errors

        // Map accounts to match frontend expectations (debit/credit instead of debit_balance/credit_balance)
        $accountsData = $accounts->values()->map(function ($account) {
            return [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type,
                'debit' => $account->debit_balance,
                'credit' => $account->credit_balance,
                'total_debit' => $account->total_debit,
                'total_credit' => $account->total_credit,
            ];
        })->toArray();

        return new ReportData(
            title: 'Trial Balance',
            data: $accountsData,
            summary: [
                'total_debits' => round($totalDebits, 2),
                'total_credits' => round($totalCredits, 2),
                'difference' => round($difference, 2),
                'is_balanced' => $isBalanced,
            ],
            metadata: [
                'as_of_date' => $asOfDate->format('Y-m-d'),
                'account_type_filter' => $accountType,
                'account_count' => $accounts->count(),
                'generated_at' => now()->toIso8601String(),
            ]
        );
    }

    /**
     * Get account balances as of a specific date for trial balance
     */
    protected function getTrialBalanceAccounts(Carbon $asOfDate, ?string $accountType = null, ?ReportFilter $filter = null)
    {
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.entry_date', '<=', $asOfDate)
            ->where('je.status', 'posted');

        // Apply account type filter if provided
        if ($accountType) {
            $query->where('coa.type', $accountType);
        }

        // Apply entity filters
        if ($filter?->legalEntityId) {
            $query->where('je.legal_entity_id', $filter->legalEntityId);
        }
        if ($filter?->entityBookId) {
            $query->where('je.entity_book_id', $filter->entityBookId);
        }

        $accounts = $query->select([
            'coa.id',
            'coa.code',
            'coa.name',
            'coa.type as account_type',
            DB::raw('SUM(jel.debit) as total_debit'),
            DB::raw('SUM(jel.credit) as total_credit'),
        ])
            ->groupBy('coa.id', 'coa.code', 'coa.name', 'coa.type')
            ->orderBy('coa.code')
            ->get();

        // Calculate debit/credit balance based on account type
        return $accounts->map(function ($account) {
            $netBalance = $account->total_debit - $account->total_credit;

            // Determine normal balance side based on account type
            $normalDebitAccounts = ['asset', 'expense'];
            $isNormalDebit = in_array($account->account_type, $normalDebitAccounts);

            if ($isNormalDebit) {
                // Asset and Expense accounts have normal debit balance
                // Positive net balance = debit, negative = credit
                $account->debit_balance = $netBalance >= 0 ? $netBalance : 0;
                $account->credit_balance = $netBalance < 0 ? abs($netBalance) : 0;
            } else {
                // Liability, Equity, and Revenue accounts have normal credit balance
                // Negative net balance = credit, positive = debit (unusual)
                $account->credit_balance = $netBalance <= 0 ? abs($netBalance) : 0;
                $account->debit_balance = $netBalance > 0 ? $netBalance : 0;
            }

            // Round to 2 decimal places
            $account->debit_balance = round($account->debit_balance, 2);
            $account->credit_balance = round($account->credit_balance, 2);
            $account->total_debit = round($account->total_debit, 2);
            $account->total_credit = round($account->total_credit, 2);

            // Remove accounts with zero balance (optional - can be configured)
            return $account;
        })->filter(function ($account) {
            // Only include accounts with non-zero balances
            return $account->debit_balance > 0 || $account->credit_balance > 0;
        })->values();
    }

    /**
     * Verify trial balance integrity
     *
     * This method checks if the trial balance is in balance
     * and returns any discrepancies
     */
    public function verifyBalance(Carbon $asOfDate): array
    {
        $accounts = $this->getTrialBalanceAccounts($asOfDate);

        $totalDebits = $accounts->sum('debit_balance');
        $totalCredits = $accounts->sum('credit_balance');
        $difference = $totalDebits - $totalCredits;

        return [
            'is_balanced' => abs($difference) < 0.01,
            'total_debits' => round($totalDebits, 2),
            'total_credits' => round($totalCredits, 2),
            'difference' => round($difference, 2),
            'account_count' => $accounts->count(),
        ];
    }

    /**
     * Get trial balance grouped by account type
     */
    public function getGroupedByAccountType(Carbon $asOfDate): array
    {
        $accounts = $this->getTrialBalanceAccounts($asOfDate);

        $grouped = $accounts->groupBy('account_type')->map(function ($typeAccounts, $type) {
            return [
                'account_type' => $type,
                'accounts' => $typeAccounts->values()->toArray(),
                'total_debits' => round($typeAccounts->sum('debit_balance'), 2),
                'total_credits' => round($typeAccounts->sum('credit_balance'), 2),
                'account_count' => $typeAccounts->count(),
            ];
        });

        return [
            'as_of_date' => $asOfDate->format('Y-m-d'),
            'groups' => $grouped->values()->toArray(),
            'total_debits' => round($accounts->sum('debit_balance'), 2),
            'total_credits' => round($accounts->sum('credit_balance'), 2),
            'is_balanced' => abs($accounts->sum('debit_balance') - $accounts->sum('credit_balance')) < 0.01,
        ];
    }
}
