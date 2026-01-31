<?php

namespace Modules\FinancialReporting\Services\Analytics;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\FinancialReporting\DTOs\ReportFilter;

class ExpenseAnalyticsService
{
    /**
     * Get expenses by category
     */
    public function getExpensesByCategory(ReportFilter $filter): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        // Get expense accounts from chart of accounts
        $expenses = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->whereBetween('je.entry_date', [$startDate, $endDate])
            ->where('je.status', 'posted')
            ->where('coa.type', 'expense')
            ->select([
                'coa.id as account_id',
                'coa.name as account_name',
                'coa.code as account_code',
                'coa.type as account_type',
                DB::raw('SUM(jel.debit - jel.credit) as total_amount'),
                DB::raw('COUNT(DISTINCT je.id) as transaction_count'),
            ])
            ->groupBy('coa.id', 'coa.name', 'coa.code', 'coa.type')
            ->orderByDesc('total_amount')
            ->get();

        $totalExpenses = $expenses->sum('total_amount');

        // Categorize expenses by account code ranges
        $categorized = [
            'cogs' => $expenses->filter(fn($e) => $e->account_code >= 5000 && $e->account_code < 6000),
            'operating' => $expenses->filter(fn($e) => $e->account_code >= 6000 && $e->account_code < 7000),
            'other' => $expenses->filter(fn($e) => $e->account_code >= 7000),
        ];

        $summary = [
            'total_expenses' => round($totalExpenses, 2),
            'total_accounts' => $expenses->count(),
            'cogs' => round($categorized['cogs']->sum('total_amount'), 2),
            'operating_expenses' => round($categorized['operating']->sum('total_amount'), 2),
            'other_expenses' => round($categorized['other']->sum('total_amount'), 2),
            'average_per_account' => $expenses->count() > 0 ? round($expenses->avg('total_amount'), 2) : 0,
        ];

        return [
            'title' => 'Expenses by Category',
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'data' => $expenses->map(fn($item) => [
                'account_id' => $item->account_id,
                'account_name' => $item->account_name,
                'account_code' => $item->account_code,
                'account_type' => $item->account_type,
                'total_amount' => round($item->total_amount, 2),
                'transaction_count' => (int) $item->transaction_count,
                'percentage' => $totalExpenses > 0 ? round(($item->total_amount / $totalExpenses) * 100, 2) : 0,
                'category' => $this->categorizeExpense($item->account_code),
            ])->toArray(),
            'summary' => $summary,
            'by_category' => [
                [
                    'category' => 'Cost of Goods Sold',
                    'amount' => $summary['cogs'],
                    'percentage' => $totalExpenses > 0 ? round(($summary['cogs'] / $totalExpenses) * 100, 2) : 0,
                ],
                [
                    'category' => 'Operating Expenses',
                    'amount' => $summary['operating_expenses'],
                    'percentage' => $totalExpenses > 0 ? round(($summary['operating_expenses'] / $totalExpenses) * 100, 2) : 0,
                ],
                [
                    'category' => 'Other Expenses',
                    'amount' => $summary['other_expenses'],
                    'percentage' => $totalExpenses > 0 ? round(($summary['other_expenses'] / $totalExpenses) * 100, 2) : 0,
                ],
            ],
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Get expenses by vendor
     */
    public function getExpensesByVendor(ReportFilter $filter): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        // Check if vendors table and dimensions column exist
        if (!$this->tableExists('vendors')) {
            return [
                'title' => 'Expenses by Vendor',
                'period' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                ],
                'data' => [],
                'summary' => [
                    'total_vendors' => 0,
                    'total_expenses' => 0,
                    'average_per_vendor' => 0,
                    'total_transactions' => 0,
                ],
                'message' => 'Vendors table not available',
                'generated_at' => now()->toISOString(),
            ];
        }

        // Check if dimensions column exists
        try {
            $hasDimensions = DB::getSchemaBuilder()->hasColumn('journal_entries', 'dimensions');
        } catch (\Exception $e) {
            $hasDimensions = false;
        }

        if (!$hasDimensions) {
            return [
                'title' => 'Expenses by Vendor',
                'period' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                ],
                'data' => [],
                'summary' => [
                    'total_vendors' => 0,
                    'total_expenses' => 0,
                    'average_per_vendor' => 0,
                    'total_transactions' => 0,
                ],
                'message' => 'Vendor tracking not enabled',
                'generated_at' => now()->toISOString(),
            ];
        }

        // Get expenses from journal entries with vendor dimension
        $expenses = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->leftJoin('vendors as v', function($join) {
                $join->on(DB::raw("(je.dimensions->>'vendor_id')::bigint"), '=', 'v.id');
            })
            ->whereBetween('je.entry_date', [$startDate, $endDate])
            ->where('je.status', 'posted')
            ->where('coa.type', 'expense')
            ->whereNotNull(DB::raw("je.dimensions->>'vendor_id'"))
            ->select([
                DB::raw("(je.dimensions->>'vendor_id')::bigint as vendor_id"),
                'v.name as vendor_name',
                DB::raw('SUM(jel.debit - jel.credit) as total_amount'),
                DB::raw('COUNT(DISTINCT je.id) as transaction_count'),
                DB::raw('AVG(jel.debit - jel.credit) as average_transaction'),
            ])
            ->groupBy('vendor_id', 'v.name')
            ->orderByDesc('total_amount')
            ->get();

        $summary = [
            'total_vendors' => $expenses->count(),
            'total_expenses' => round($expenses->sum('total_amount'), 2),
            'average_per_vendor' => $expenses->count() > 0 ? round($expenses->avg('total_amount'), 2) : 0,
            'total_transactions' => (int) $expenses->sum('transaction_count'),
        ];

        return [
            'title' => 'Expenses by Vendor',
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'data' => $expenses->map(fn($item) => [
                'vendor_id' => $item->vendor_id,
                'vendor_name' => $item->vendor_name ?? 'Unknown',
                'total_spending' => round($item->total_amount, 2),
                'invoice_count' => (int) $item->transaction_count,
                'average_invoice' => round($item->average_transaction, 2),
            ])->toArray(),
            'summary' => $summary,
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Get expenses by department
     */
    public function getExpensesByDepartment(ReportFilter $filter): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        // Check if departments table and dimensions column exist
        if (!$this->tableExists('departments')) {
            return [
                'title' => 'Expenses by Department',
                'period' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                ],
                'data' => [],
                'summary' => [
                    'total_departments' => 0,
                    'total_expenses' => 0,
                    'average_per_department' => 0,
                    'total_transactions' => 0,
                ],
                'message' => 'Departments table not available',
                'generated_at' => now()->toISOString(),
            ];
        }

        // Check if dimensions column exists
        try {
            $hasDimensions = DB::getSchemaBuilder()->hasColumn('journal_entries', 'dimensions');
        } catch (\Exception $e) {
            $hasDimensions = false;
        }

        if (!$hasDimensions) {
            return [
                'title' => 'Expenses by Department',
                'period' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                ],
                'data' => [],
                'summary' => [
                    'total_departments' => 0,
                    'total_expenses' => 0,
                    'average_per_department' => 0,
                    'total_transactions' => 0,
                ],
                'message' => 'Department tracking not enabled',
                'generated_at' => now()->toISOString(),
            ];
        }

        // Get expenses from journal entries with department dimension
        $expenses = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->leftJoin('departments as d', function($join) {
                $join->on(DB::raw("(je.dimensions->>'department_id')::bigint"), '=', 'd.id');
            })
            ->whereBetween('je.entry_date', [$startDate, $endDate])
            ->where('je.status', 'posted')
            ->where('coa.type', 'expense')
            ->whereNotNull(DB::raw("je.dimensions->>'department_id'"))
            ->select([
                DB::raw("(je.dimensions->>'department_id')::bigint as department_id"),
                'd.name as department_name',
                DB::raw('SUM(jel.debit - jel.credit) as total_amount'),
                DB::raw('COUNT(DISTINCT je.id) as transaction_count'),
            ])
            ->groupBy('department_id', 'd.name')
            ->orderByDesc('total_amount')
            ->get();

        $totalExpenses = $expenses->sum('total_amount');

        $summary = [
            'total_departments' => $expenses->count(),
            'total_expenses' => round($totalExpenses, 2),
            'average_per_department' => $expenses->count() > 0 ? round($expenses->avg('total_amount'), 2) : 0,
            'total_transactions' => (int) $expenses->sum('transaction_count'),
        ];

        return [
            'title' => 'Expenses by Department',
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'data' => $expenses->map(fn($item) => [
                'department_id' => $item->department_id,
                'department_name' => $item->department_name ?? 'Unknown',
                'total_expenses' => round($item->total_amount, 2),
                'transaction_count' => (int) $item->transaction_count,
                'percentage' => $totalExpenses > 0 ? round(($item->total_amount / $totalExpenses) * 100, 2) : 0,
            ])->toArray(),
            'summary' => $summary,
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Get expense trends
     */
    public function getExpenseTrends(ReportFilter $filter, string $interval = 'month'): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfYear();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        // Determine date format based on interval
        $dateFormat = match($interval) {
            'day' => 'YYYY-MM-DD',
            'week' => 'IYYY-IW',
            'month' => 'YYYY-MM',
            'year' => 'YYYY',
            default => 'YYYY-MM',
        };

        $trends = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->whereBetween('je.entry_date', [$startDate, $endDate])
            ->where('je.status', 'posted')
            ->where('coa.type', 'expense')
            ->select([
                DB::raw("TO_CHAR(je.entry_date, '{$dateFormat}') as period"),
                DB::raw('SUM(jel.debit - jel.credit) as total_amount'),
                DB::raw('COUNT(DISTINCT je.id) as transaction_count'),
                DB::raw('AVG(jel.debit - jel.credit) as average_amount'),
            ])
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        // Calculate growth rates
        $data = [];
        $previousAmount = null;

        foreach ($trends as $trend) {
            $growthRate = null;
            if ($previousAmount !== null && $previousAmount > 0) {
                $growthRate = (($trend->total_amount - $previousAmount) / $previousAmount) * 100;
            }

            $data[] = [
                'period' => $trend->period,
                'amount' => round($trend->total_amount, 2),
                'transaction_count' => (int) $trend->transaction_count,
                'average_amount' => round($trend->average_amount, 2),
                'growth_rate' => $growthRate !== null ? round($growthRate, 2) : null,
            ];

            $previousAmount = $trend->total_amount;
        }

        $summary = [
            'total_periods' => count($data),
            'total_expenses' => round($trends->sum('total_amount'), 2),
            'average_per_period' => count($data) > 0 ? round($trends->avg('total_amount'), 2) : 0,
            'highest_expense_period' => $trends->sortByDesc('total_amount')->first()?->period,
            'lowest_expense_period' => $trends->sortBy('total_amount')->first()?->period,
        ];

        return [
            'title' => 'Expense Trends',
            'interval' => $interval,
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'data' => $data,
            'summary' => $summary,
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Get top expense accounts
     */
    public function getTopExpenseAccounts(ReportFilter $filter, int $limit = 10): array
    {
        $report = $this->getExpensesByCategory($filter);
        
        if (isset($report['data'])) {
            $report['data'] = array_slice($report['data'], 0, $limit);
            $report['title'] = "Top {$limit} Expense Accounts";
        }
        
        return $report;
    }

    /**
     * Categorize expense by account code
     */
    protected function categorizeExpense(int $code): string
    {
        if ($code >= 5000 && $code < 6000) {
            return 'Cost of Goods Sold';
        } elseif ($code >= 6000 && $code < 7000) {
            return 'Operating Expenses';
        } elseif ($code >= 7000) {
            return 'Other Expenses';
        }
        
        return 'Uncategorized';
    }

    /**
     * Check if table exists
     */
    protected function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Exception $e) {
            return false;
        }
    }
}
