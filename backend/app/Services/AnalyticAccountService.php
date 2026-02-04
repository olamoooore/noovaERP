<?php

namespace App\Services;

use App\Models\AnalyticAccount;
use App\Models\JournalEntryLine;
use App\Models\ChartOfAccount;

/**
 * Analytic Account Service
 * Handles reporting and analysis by cost centers, projects, departments, locations
 */
class AnalyticAccountService
{
    /**
     * Get balance for an analytic account including all children
     */
    public function getBalanceWithChildren(
        AnalyticAccount $account,
        string $dimension,
        $startDate = null,
        $endDate = null
    ): float {
        $balance = $account->getBalance($dimension, $startDate, $endDate);

        // Add balances from all children recursively
        foreach ($account->children as $child) {
            $balance += $this->getBalanceWithChildren($child, $dimension, $startDate, $endDate);
        }

        return $balance;
    }

    /**
     * Get Profit & Loss report for an analytic account
     */
    public function getProfitLoss(
        AnalyticAccount $account,
        string $dimension,
        $startDate = null,
        $endDate = null
    ): array {
        $query = JournalEntryLine::with(['account', 'journalEntry'])
            ->whereHas('journalEntry', function ($q) use ($startDate, $endDate) {
                $q->where('status', 'posted');
                if ($startDate) {
                    $q->where('entry_date', '>=', $startDate);
                }
                if ($endDate) {
                    $q->where('entry_date', '<=', $endDate);
                }
            });

        // Filter by dimension
        switch ($dimension) {
            case 'department':
                $query->where('department_id', $account->id);
                break;
            case 'project':
                $query->where('project_id', $account->id);
                break;
            case 'location':
                $query->where('location_id', $account->id);
                break;
        }

        $lines = $query->get();

        $revenues = 0;
        $expenses = 0;

        foreach ($lines as $line) {
            if ($line->account->type === 'revenue') {
                $revenues += (float) $line->credit - (float) $line->debit;
            } elseif ($line->account->type === 'expense') {
                $expenses += (float) $line->debit - (float) $line->credit;
            }
        }

        $netIncome = $revenues - $expenses;

        return [
            'account' => $account,
            'dimension' => $dimension,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'revenues' => $revenues,
            'expenses' => $expenses,
            'net_income' => $netIncome,
        ];
    }

    /**
     * Get report by dimension (all accounts of a type)
     */
    public function getReportByDimension(
        string $dimension,
        $startDate = null,
        $endDate = null
    ): array {
        // Map dimension to type
        $typeMap = [
            'department' => 'department',
            'project' => 'project',
            'location' => 'location',
        ];

        $type = $typeMap[$dimension] ?? $dimension;

        $accounts = AnalyticAccount::where('type', $type)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $results = [];

        foreach ($accounts as $account) {
            $pl = $this->getProfitLoss($account, $dimension, $startDate, $endDate);
            
            $results[] = [
                'account_id' => $account->id,
                'account_code' => $account->code,
                'account_name' => $account->name,
                'revenues' => $pl['revenues'],
                'expenses' => $pl['expenses'],
                'net_income' => $pl['net_income'],
            ];
        }

        // Calculate totals
        $totalRevenues = array_sum(array_column($results, 'revenues'));
        $totalExpenses = array_sum(array_column($results, 'expenses'));
        $totalNetIncome = $totalRevenues - $totalExpenses;

        return [
            'dimension' => $dimension,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'accounts' => $results,
            'totals' => [
                'revenues' => $totalRevenues,
                'expenses' => $totalExpenses,
                'net_income' => $totalNetIncome,
            ],
        ];
    }

    /**
     * Get detailed transactions for an analytic account
     */
    public function getTransactions(
        AnalyticAccount $account,
        string $dimension,
        $startDate = null,
        $endDate = null
    ): array {
        $query = JournalEntryLine::with(['account', 'journalEntry'])
            ->whereHas('journalEntry', function ($q) use ($startDate, $endDate) {
                $q->where('status', 'posted');
                if ($startDate) {
                    $q->where('entry_date', '>=', $startDate);
                }
                if ($endDate) {
                    $q->where('entry_date', '<=', $endDate);
                }
            });

        // Filter by dimension
        switch ($dimension) {
            case 'department':
                $query->where('department_id', $account->id);
                break;
            case 'project':
                $query->where('project_id', $account->id);
                break;
            case 'location':
                $query->where('location_id', $account->id);
                break;
        }

        $lines = $query->orderBy('id', 'desc')->get();

        return $lines->map(function ($line) {
            return [
                'date' => $line->journalEntry->entry_date->format('Y-m-d'),
                'entry_number' => $line->journalEntry->entry_number,
                'account_code' => $line->account->code,
                'account_name' => $line->account->name,
                'description' => $line->description ?? $line->journalEntry->description,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
            ];
        })->toArray();
    }

    /**
     * Get budget vs actual comparison
     */
    public function getBudgetComparison(
        AnalyticAccount $account,
        string $dimension,
        $startDate = null,
        $endDate = null
    ): array {
        $pl = $this->getProfitLoss($account, $dimension, $startDate, $endDate);

        $budgetAmount = (float) $account->budget_amount;
        $actualExpenses = $pl['expenses'];
        $variance = $budgetAmount - $actualExpenses;
        $variancePercent = $budgetAmount > 0 ? ($variance / $budgetAmount) * 100 : 0;

        return [
            'account' => $account,
            'dimension' => $dimension,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'budget' => $budgetAmount,
            'actual_expenses' => $actualExpenses,
            'actual_revenues' => $pl['revenues'],
            'variance' => $variance,
            'variance_percent' => $variancePercent,
            'status' => $variance >= 0 ? 'under_budget' : 'over_budget',
        ];
    }

    /**
     * Validate if analytic account is active
     */
    public function validateActive(int $accountId): bool
    {
        $account = AnalyticAccount::find($accountId);
        return $account && $account->is_active;
    }

    /**
     * Get all active analytic accounts by type
     */
    public function getActiveByType(string $type): array
    {
        return AnalyticAccount::where('type', $type)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->toArray();
    }
}
