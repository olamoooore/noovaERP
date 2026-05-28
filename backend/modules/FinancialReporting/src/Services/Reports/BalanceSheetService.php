<?php

namespace Modules\FinancialReporting\Services\Reports;

use Modules\FinancialReporting\Services\BaseReportService;
use Modules\FinancialReporting\Services\ReportMappingReader;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\DTOs\ReportData;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BalanceSheetService extends BaseReportService
{
    protected ReportMappingReader $mappingReader;

    public function __construct(ReportMappingReader $mappingReader)
    {
        parent::__construct();
        $this->mappingReader = $mappingReader;
    }
    /**
     * Generate Balance Sheet report
     */
    public function generate(ReportFilter $filter): ReportData
    {
        // Validate as_of_date
        if (!$filter->asOfDate) {
            throw new \InvalidArgumentException('As of date is required for Balance Sheet report');
        }

        // Determine if we should use summary table
        $useSummary = $this->shouldUseSummary($filter->asOfDate);

        // Get assets
        $currentAssets = $this->getAssetsByCategory($filter, 'current_asset', $useSummary);
        $fixedAssets = $this->getAssetsByCategory($filter, 'fixed_asset', $useSummary);
        $otherAssets = $this->getAssetsByCategory($filter, 'other_asset', $useSummary);
        $totalAssets = $currentAssets['total'] + $fixedAssets['total'] + $otherAssets['total'];

        // Get liabilities
        $currentLiabilities = $this->getLiabilitiesByCategory($filter, 'current_liability', $useSummary);
        $longTermLiabilities = $this->getLiabilitiesByCategory($filter, 'long_term_liability', $useSummary);
        $totalLiabilities = $currentLiabilities['total'] + $longTermLiabilities['total'];

        // Get equity
        $equity = $this->getEquity($filter, $useSummary);
        $retainedEarnings = $this->getRetainedEarnings($filter, $useSummary);
        $totalEquity = $equity + $retainedEarnings;

        // Calculate working capital
        $workingCapital = $currentAssets['total'] - $currentLiabilities['total'];

        // Build report data
        $data = [
            'assets' => [
                'current_assets' => $currentAssets,
                'fixed_assets' => $fixedAssets,
                'other_assets' => $otherAssets,
                'total' => $totalAssets,
            ],
            'liabilities' => [
                'current_liabilities' => $currentLiabilities,
                'long_term_liabilities' => $longTermLiabilities,
                'total' => $totalLiabilities,
            ],
            'equity' => [
                'capital' => $equity,
                'retained_earnings' => $retainedEarnings,
                'total' => $totalEquity,
            ],
            'total_liabilities_and_equity' => $totalLiabilities + $totalEquity,
        ];

        // Add comparison if requested
        $comparison = null;
        if ($filter->compareStartDate) {
            $comparison = $this->generateComparison($filter);
        }

        $reportData = new ReportData(
            title: 'Balance Sheet',
            data: $data,
            summary: [
                'total_assets' => $totalAssets,
                'total_liabilities' => $totalLiabilities,
                'total_equity' => $totalEquity,
                'working_capital' => $workingCapital,
                'current_ratio' => $currentLiabilities['total'] > 0 
                    ? $currentAssets['total'] / $currentLiabilities['total'] 
                    : 0,
                'debt_to_equity_ratio' => $totalEquity > 0 
                    ? $totalLiabilities / $totalEquity 
                    : 0,
            ],
            metadata: [
                'as_of_date' => $filter->asOfDate->format('Y-m-d'),
                'generated_at' => now()->toIso8601String(),
                'used_summary_table' => $useSummary,
            ],
            comparison: $comparison
        );

        return $reportData;
    }

    /**
     * Get assets by category
     */
    protected function getAssetsByCategory(ReportFilter $filter, string $category, bool $useSummary): array
    {
        $accounts = $this->getAccountsByTypeAndCategory($filter, 'asset', $category, $useSummary);
        
        $total = array_sum(array_column($accounts, 'balance'));

        return [
            'total' => $total,
            'accounts' => $accounts,
        ];
    }

    /**
     * Get liabilities by category
     */
    protected function getLiabilitiesByCategory(ReportFilter $filter, string $category, bool $useSummary): array
    {
        $accounts = $this->getAccountsByTypeAndCategory($filter, 'liability', $category, $useSummary);
        
        $total = array_sum(array_column($accounts, 'balance'));

        return [
            'total' => $total,
            'accounts' => $accounts,
        ];
    }

    /**
     * Get equity (excluding retained earnings)
     */
    protected function getEquity(ReportFilter $filter, bool $useSummary): float
    {
        if ($useSummary) {
            return $this->getEquityFromSummary($filter);
        }

        return $this->getEquityFromJournal($filter);
    }

    /**
     * Get retained earnings (calculated from revenue and expenses)
     */
    protected function getRetainedEarnings(ReportFilter $filter, bool $useSummary): float
    {
        // Retained earnings = Revenue - Expenses (cumulative)
        $revenueFilter = new ReportFilter(
            asOfDate: $filter->asOfDate,
            accountType: 'revenue',
            legalEntityId: $filter->legalEntityId,
            entityBookId: $filter->entityBookId,
        );

        $expenseFilter = new ReportFilter(
            asOfDate: $filter->asOfDate,
            accountType: 'expense',
            legalEntityId: $filter->legalEntityId,
            entityBookId: $filter->entityBookId,
        );

        if ($useSummary) {
            $revenue = $this->getTotalFromSummary($revenueFilter, 'revenue');
            $expenses = $this->getTotalFromSummary($expenseFilter, 'expense');
        } else {
            $revenue = $this->getTotalFromJournal($revenueFilter, 'revenue');
            $expenses = $this->getTotalFromJournal($expenseFilter, 'expense');
        }

        return $revenue - $expenses;
    }

    /**
     * Get accounts by type and category
     */
    protected function getAccountsByTypeAndCategory(
        ReportFilter $filter,
        string $type,
        string $category,
        bool $useSummary
    ): array {
        if ($useSummary) {
            $query = DB::table('daily_ledger_summaries as dls')
                ->join('chart_of_accounts as coa', 'coa.id', '=', 'dls.account_id')
                ->where('coa.type', $type)
                ->where('dls.summary_date', '<=', $filter->asOfDate)
                ->select([
                    'coa.id',
                    'coa.code',
                    'coa.name',
                    DB::raw('SUM(dls.net_amount) as balance'),
                ])
                ->groupBy('coa.id', 'coa.code', 'coa.name');
        } else {
            $query = DB::table('journal_entry_lines as jel')
                ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
                ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
                ->where('je.status', 'posted')
                ->where('coa.type', $type)
                ->where('je.entry_date', '<=', $filter->asOfDate)
                ->select([
                    'coa.id',
                    'coa.code',
                    'coa.name',
                    DB::raw('SUM(jel.debit - jel.credit) as balance'),
                ])
                ->groupBy('coa.id', 'coa.code', 'coa.name');
        }

        // Filter by category using report tag mappings
        $tagCode = $this->getCategoryTagCode($type, $category);
        if ($tagCode) {
            $this->mappingReader->applyFilter($query, $tagCode);
        }

        // Apply entity filters
        if ($useSummary) {
            if ($filter->legalEntityId) {
                $query->where('dls.legal_entity_id', $filter->legalEntityId);
            }
            if ($filter->entityBookId) {
                $query->where('dls.entity_book_id', $filter->entityBookId);
            }
        } else {
            if ($filter->legalEntityId) {
                $query->where('je.legal_entity_id', $filter->legalEntityId);
            }
            if ($filter->entityBookId) {
                $query->where('je.entity_book_id', $filter->entityBookId);
            }
        }

        return $query->orderBy('coa.code')
            ->get()
            ->map(fn($row) => [
                'account_id' => $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'balance' => (float) $row->balance,
            ])
            ->toArray();
    }

    /**
     * Get category tag code for report mapping
     */
    protected function getCategoryTagCode(string $type, string $category): ?string
    {
        $map = [
            'asset' => [
                'current_asset' => 'BS_CURRENT_ASSETS',
                'fixed_asset' => 'BS_NON_CURRENT_ASSETS',
                'other_asset' => 'BS_OTHER_ASSETS',
            ],
            'liability' => [
                'current_liability' => 'BS_CURRENT_LIABILITIES',
                'long_term_liability' => 'BS_NON_CURRENT_LIABILITIES',
            ],
        ];

        return $map[$type][$category] ?? null;
    }

    /**
     * Get category code ranges (legacy, delegated to ReportMappingReader)
     * @deprecated Use getCategoryTagCode() + ReportMappingReader instead
     */
    protected function getCategoryCodeRanges(string $type, string $category): ?array
    {
        $ranges = [
            'asset' => [
                'current_asset' => ['1000-1299'],
                'fixed_asset' => ['1300-1899'],
                'other_asset' => ['1900-1999'],
            ],
            'liability' => [
                'current_liability' => ['2000-2199'],
                'long_term_liability' => ['2200-2999'],
            ],
        ];

        return $ranges[$type][$category] ?? null;
    }

    /**
     * Get equity from summary table
     */
    protected function getEquityFromSummary(ReportFilter $filter): float
    {
        $query = DB::table('daily_ledger_summaries as dls')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'dls.account_id')
            ->where('coa.type', 'equity')
            ->where('dls.summary_date', '<=', $filter->asOfDate);

        $this->mappingReader->applyFilter($query, 'BS_EQUITY');

        if ($filter->legalEntityId) {
            $query->where('dls.legal_entity_id', $filter->legalEntityId);
        }
        if ($filter->entityBookId) {
            $query->where('dls.entity_book_id', $filter->entityBookId);
        }

        return $query->sum('dls.net_amount');
    }

    /**
     * Get equity from journal entries
     */
    protected function getEquityFromJournal(ReportFilter $filter): float
    {
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')
            ->where('coa.type', 'equity')
            ->where('je.entry_date', '<=', $filter->asOfDate);

        $this->mappingReader->applyFilter($query, 'BS_EQUITY');

        if ($filter->legalEntityId) {
            $query->where('je.legal_entity_id', $filter->legalEntityId);
        }
        if ($filter->entityBookId) {
            $query->where('je.entity_book_id', $filter->entityBookId);
        }

        return $query->sum(DB::raw('jel.credit - jel.debit'));
    }

    /**
     * Get total from summary table
     */
    protected function getTotalFromSummary(ReportFilter $filter, string $accountType): float
    {
        $query = DB::table('daily_ledger_summaries as dls')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'dls.account_id')
            ->where('coa.type', $accountType)
            ->where('dls.summary_date', '<=', $filter->asOfDate);

        if ($filter->legalEntityId) {
            $query->where('dls.legal_entity_id', $filter->legalEntityId);
        }
        if ($filter->entityBookId) {
            $query->where('dls.entity_book_id', $filter->entityBookId);
        }

        return abs($query->sum('dls.net_amount'));
    }

    /**
     * Get total from journal entries
     */
    protected function getTotalFromJournal(ReportFilter $filter, string $accountType): float
    {
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')
            ->where('coa.type', $accountType)
            ->where('je.entry_date', '<=', $filter->asOfDate);

        if ($filter->legalEntityId) {
            $query->where('je.legal_entity_id', $filter->legalEntityId);
        }
        if ($filter->entityBookId) {
            $query->where('je.entity_book_id', $filter->entityBookId);
        }

        if ($accountType === 'revenue') {
            return $query->sum('jel.credit');
        } else {
            return $query->sum('jel.debit');
        }
    }

    /**
     * Generate comparison data
     */
    protected function generateComparison(ReportFilter $filter): array
    {
        $compareFilter = new ReportFilter(
            asOfDate: $filter->compareStartDate
        );

        $compareReport = $this->generate($compareFilter);
        $compareData = $compareReport->data;
        $compareSummary = $compareReport->summary;

        $currentSummary = [
            'total_assets' => $filter->asOfDate ? 
                $this->generate(new ReportFilter(asOfDate: $filter->asOfDate))->summary['total_assets'] : 0,
            'total_liabilities' => $filter->asOfDate ? 
                $this->generate(new ReportFilter(asOfDate: $filter->asOfDate))->summary['total_liabilities'] : 0,
            'total_equity' => $filter->asOfDate ? 
                $this->generate(new ReportFilter(asOfDate: $filter->asOfDate))->summary['total_equity'] : 0,
        ];

        return [
            'as_of_date' => $compareFilter->asOfDate->format('Y-m-d'),
            'total_assets' => [
                'amount' => $compareSummary['total_assets'],
                'change' => $currentSummary['total_assets'] - $compareSummary['total_assets'],
                'change_percent' => $this->calculatePercentageChange(
                    $currentSummary['total_assets'],
                    $compareSummary['total_assets']
                ),
            ],
            'total_liabilities' => [
                'amount' => $compareSummary['total_liabilities'],
                'change' => $currentSummary['total_liabilities'] - $compareSummary['total_liabilities'],
                'change_percent' => $this->calculatePercentageChange(
                    $currentSummary['total_liabilities'],
                    $compareSummary['total_liabilities']
                ),
            ],
            'total_equity' => [
                'amount' => $compareSummary['total_equity'],
                'change' => $currentSummary['total_equity'] - $compareSummary['total_equity'],
                'change_percent' => $this->calculatePercentageChange(
                    $currentSummary['total_equity'],
                    $compareSummary['total_equity']
                ),
            ],
        ];
    }
}
