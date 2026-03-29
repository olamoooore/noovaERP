<?php

namespace Modules\FinancialReporting\Services\Reports;

use Modules\FinancialReporting\Services\BaseReportService;
use Modules\FinancialReporting\Services\ReportMappingReader;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\DTOs\ReportData;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProfitLossService extends BaseReportService
{
    protected ReportMappingReader $mappingReader;

    public function __construct(ReportMappingReader $mappingReader)
    {
        parent::__construct();
        $this->mappingReader = $mappingReader;
    }
    /**
     * Generate Profit & Loss report
     */
    public function generate(ReportFilter $filter): ReportData
    {
        // Validate date range
        if (!$filter->startDate || !$filter->endDate) {
            throw new \InvalidArgumentException('Start date and end date are required for P&L report');
        }

        // Determine if we should use summary table
        $useSummary = $this->shouldUseSummary($filter->startDate);

        // Get revenue and expense data
        $revenue = $this->getRevenue($filter, $useSummary);
        $cogs = $this->getCostOfGoodsSold($filter, $useSummary);
        $operatingExpenses = $this->getOperatingExpenses($filter, $useSummary);
        $otherIncome = $this->getOtherIncome($filter, $useSummary);
        $otherExpenses = $this->getOtherExpenses($filter, $useSummary);

        // Calculate profit metrics
        $grossProfit = $revenue - $cogs;
        $operatingProfit = $grossProfit - $operatingExpenses;
        $netProfit = $operatingProfit + $otherIncome - $otherExpenses;

        // Get detailed breakdown
        $revenueDetails = $this->getAccountDetails($filter, 'revenue', $useSummary);
        $cogsDetails = $this->getAccountDetails($filter, 'expense', $useSummary, 'PL_COGS');
        $opexDetails = $this->getAccountDetails($filter, 'expense', $useSummary, 'PL_OPERATING_EXPENSES');
        $otherIncomeDetails = $this->getAccountDetails($filter, 'revenue', $useSummary, 'PL_OTHER_INCOME');
        $otherExpenseDetails = $this->getAccountDetails($filter, 'expense', $useSummary, 'PL_OTHER_EXPENSES');

        // Build report data
        $data = [
            'revenue' => [
                'total' => $revenue,
                'accounts' => $revenueDetails,
            ],
            'cost_of_goods_sold' => [
                'total' => $cogs,
                'accounts' => $cogsDetails,
            ],
            'gross_profit' => [
                'amount' => $grossProfit,
                'margin_percent' => $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0,
            ],
            'operating_expenses' => [
                'total' => $operatingExpenses,
                'accounts' => $opexDetails,
            ],
            'operating_profit' => [
                'amount' => $operatingProfit,
                'margin_percent' => $revenue > 0 ? ($operatingProfit / $revenue) * 100 : 0,
            ],
            'other_income' => [
                'total' => $otherIncome,
                'accounts' => $otherIncomeDetails,
            ],
            'other_expenses' => [
                'total' => $otherExpenses,
                'accounts' => $otherExpenseDetails,
            ],
            'net_profit' => [
                'amount' => $netProfit,
                'margin_percent' => $revenue > 0 ? ($netProfit / $revenue) * 100 : 0,
            ],
        ];

        // Add comparison if requested
        $comparison = null;
        if ($filter->compareStartDate && $filter->compareEndDate) {
            $comparison = $this->generateComparison($filter);
        }

        $reportData = new ReportData(
            title: 'Profit & Loss Statement',
            data: $data,
            summary: [
                'revenue' => $revenue,
                'gross_profit' => $grossProfit,
                'operating_profit' => $operatingProfit,
                'net_profit' => $netProfit,
                'gross_margin' => $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0,
                'operating_margin' => $revenue > 0 ? ($operatingProfit / $revenue) * 100 : 0,
                'net_margin' => $revenue > 0 ? ($netProfit / $revenue) * 100 : 0,
            ],
            metadata: [
                'period' => [
                    'start' => $filter->startDate->format('Y-m-d'),
                    'end' => $filter->endDate->format('Y-m-d'),
                ],
                'generated_at' => now()->toIso8601String(),
                'used_summary_table' => $useSummary,
            ],
            comparison: $comparison
        );

        return $reportData;
    }

    /**
     * Get total revenue
     */
    protected function getRevenue(ReportFilter $filter, bool $useSummary): float
    {
        if ($useSummary) {
            return $this->getAmountFromSummary($filter, 'revenue');
        }

        return $this->getAmountFromJournal($filter, 'revenue');
    }

    /**
     * Get cost of goods sold
     */
    protected function getCostOfGoodsSold(ReportFilter $filter, bool $useSummary): float
    {
        if ($useSummary) {
            return $this->getAmountFromSummary($filter, 'expense', 'PL_COGS');
        }

        return $this->getAmountFromJournal($filter, 'expense', 'PL_COGS');
    }

    /**
     * Get operating expenses
     */
    protected function getOperatingExpenses(ReportFilter $filter, bool $useSummary): float
    {
        if ($useSummary) {
            return $this->getAmountFromSummary($filter, 'expense', 'PL_OPERATING_EXPENSES');
        }

        return $this->getAmountFromJournal($filter, 'expense', 'PL_OPERATING_EXPENSES');
    }

    /**
     * Get other income
     */
    protected function getOtherIncome(ReportFilter $filter, bool $useSummary): float
    {
        if ($useSummary) {
            return $this->getAmountFromSummary($filter, 'revenue', 'PL_OTHER_INCOME');
        }

        return $this->getAmountFromJournal($filter, 'revenue', 'PL_OTHER_INCOME');
    }

    /**
     * Get other expenses
     */
    protected function getOtherExpenses(ReportFilter $filter, bool $useSummary): float
    {
        if ($useSummary) {
            return $this->getAmountFromSummary($filter, 'expense', 'PL_OTHER_EXPENSES');
        }

        return $this->getAmountFromJournal($filter, 'expense', 'PL_OTHER_EXPENSES');
    }

    /**
     * Get amount from summary table
     */
    protected function getAmountFromSummary(ReportFilter $filter, string $accountType, ?string $tagCode = null): float
    {
        $query = DB::table('daily_ledger_summaries as dls')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'dls.account_id')
            ->where('coa.type', $accountType)
            ->whereBetween('dls.summary_date', [$filter->startDate, $filter->endDate]);

        if ($tagCode) {
            $this->mappingReader->applyFilter($query, $tagCode);
        }

        // Apply dimension filters
        $this->applyDimensionFilters($query, $filter, 'dls');

        $result = $query->sum('dls.net_amount');

        // For revenue, credit is positive; for expenses, debit is positive
        return $accountType === 'revenue' ? abs($result) : abs($result);
    }

    /**
     * Get amount from journal entries
     */
    protected function getAmountFromJournal(ReportFilter $filter, string $accountType, ?string $tagCode = null): float
    {
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')
            ->where('coa.type', $accountType)
            ->whereBetween('je.entry_date', [$filter->startDate, $filter->endDate]);

        if ($tagCode) {
            $this->mappingReader->applyFilter($query, $tagCode);
        }

        // Apply dimension filters
        $this->applyDimensionFilters($query, $filter, 'jel');

        if ($accountType === 'revenue') {
            return $query->sum('jel.credit');
        } else {
            return $query->sum('jel.debit');
        }
    }

    /**
     * Get account details
     */
    protected function getAccountDetails(ReportFilter $filter, string $accountType, bool $useSummary, ?string $tagCode = null): array
    {
        if ($useSummary) {
            $query = DB::table('daily_ledger_summaries as dls')
                ->join('chart_of_accounts as coa', 'coa.id', '=', 'dls.account_id')
                ->where('coa.type', $accountType)
                ->whereBetween('dls.summary_date', [$filter->startDate, $filter->endDate])
                ->select([
                    'coa.id',
                    'coa.code',
                    'coa.name',
                    DB::raw('SUM(dls.net_amount) as amount'),
                ])
                ->groupBy('coa.id', 'coa.code', 'coa.name');
        } else {
            $query = DB::table('journal_entry_lines as jel')
                ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
                ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
                ->where('je.status', 'posted')
                ->where('coa.type', $accountType)
                ->whereBetween('je.entry_date', [$filter->startDate, $filter->endDate])
                ->select([
                    'coa.id',
                    'coa.code',
                    'coa.name',
                    DB::raw($accountType === 'revenue' ? 'SUM(jel.credit) as amount' : 'SUM(jel.debit) as amount'),
                ])
                ->groupBy('coa.id', 'coa.code', 'coa.name');
        }

        if ($tagCode) {
            $this->mappingReader->applyFilter($query, $tagCode);
        }

        return $query->orderBy('coa.code')
            ->get()
            ->map(fn($row) => [
                'account_id' => $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'amount' => (float) abs($row->amount),
            ])
            ->toArray();
    }

    /**
     * Apply dimension filters to query
     */
    protected function applyDimensionFilters($query, ReportFilter $filter, string $tableAlias): void
    {
        if ($filter->departmentId || $filter->projectId || $filter->locationId) {
            $query->where(function ($q) use ($filter, $tableAlias) {
                if ($filter->departmentId) {
                    $q->whereJsonContains("{$tableAlias}.dimensions->department_id", $filter->departmentId);
                }
                if ($filter->projectId) {
                    $q->whereJsonContains("{$tableAlias}.dimensions->project_id", $filter->projectId);
                }
                if ($filter->locationId) {
                    $q->whereJsonContains("{$tableAlias}.dimensions->location_id", $filter->locationId);
                }
            });
        }
    }

    /**
     * Generate comparison data
     */
    protected function generateComparison(ReportFilter $filter): array
    {
        $compareFilter = new ReportFilter(
            startDate: $filter->compareStartDate,
            endDate: $filter->compareEndDate,
            accountType: $filter->accountType,
            departmentId: $filter->departmentId,
            projectId: $filter->projectId,
            locationId: $filter->locationId
        );

        $useSummary = $this->shouldUseSummary($compareFilter->startDate);

        $compareRevenue = $this->getRevenue($compareFilter, $useSummary);
        $compareCogs = $this->getCostOfGoodsSold($compareFilter, $useSummary);
        $compareOpex = $this->getOperatingExpenses($compareFilter, $useSummary);
        $compareOtherIncome = $this->getOtherIncome($compareFilter, $useSummary);
        $compareOtherExpenses = $this->getOtherExpenses($compareFilter, $useSummary);

        $compareGrossProfit = $compareRevenue - $compareCogs;
        $compareOperatingProfit = $compareGrossProfit - $compareOpex;
        $compareNetProfit = $compareOperatingProfit + $compareOtherIncome - $compareOtherExpenses;

        // Get current period values
        $currentRevenue = $this->getRevenue($filter, $this->shouldUseSummary($filter->startDate));
        $currentGrossProfit = $currentRevenue - $this->getCostOfGoodsSold($filter, $this->shouldUseSummary($filter->startDate));
        $currentOperatingProfit = $currentGrossProfit - $this->getOperatingExpenses($filter, $this->shouldUseSummary($filter->startDate));
        $currentNetProfit = $currentOperatingProfit + $this->getOtherIncome($filter, $this->shouldUseSummary($filter->startDate)) - $this->getOtherExpenses($filter, $this->shouldUseSummary($filter->startDate));

        return [
            'period' => [
                'start' => $compareFilter->startDate->format('Y-m-d'),
                'end' => $compareFilter->endDate->format('Y-m-d'),
            ],
            'revenue' => [
                'amount' => $compareRevenue,
                'change' => $currentRevenue - $compareRevenue,
                'change_percent' => $this->calculatePercentageChange($currentRevenue, $compareRevenue),
            ],
            'gross_profit' => [
                'amount' => $compareGrossProfit,
                'change' => $currentGrossProfit - $compareGrossProfit,
                'change_percent' => $this->calculatePercentageChange($currentGrossProfit, $compareGrossProfit),
            ],
            'operating_profit' => [
                'amount' => $compareOperatingProfit,
                'change' => $currentOperatingProfit - $compareOperatingProfit,
                'change_percent' => $this->calculatePercentageChange($currentOperatingProfit, $compareOperatingProfit),
            ],
            'net_profit' => [
                'amount' => $compareNetProfit,
                'change' => $currentNetProfit - $compareNetProfit,
                'change_percent' => $this->calculatePercentageChange($currentNetProfit, $compareNetProfit),
            ],
        ];
    }
}
