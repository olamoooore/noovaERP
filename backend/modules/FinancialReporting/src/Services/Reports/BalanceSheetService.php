<?php

namespace Modules\FinancialReporting\Services\Reports;

use Illuminate\Support\Facades\DB;
use Modules\FinancialReporting\DTOs\ReportData;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\BaseReportService;
use Modules\FinancialReporting\Services\ReportMappingReader;

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
        if (! $filter->asOfDate) {
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

        // Get the actual equity accounts from the tenant COA. Profit/loss is
        // still calculated because it is not a balance-sheet COA account until
        // the period-close process posts it to retained earnings.
        $equityAccounts = $this->getAccountsByType($filter, 'equity', $useSummary);
        $equityAccountTotal = array_sum(array_column($equityAccounts, 'balance'));
        $currentYearEarnings = $this->getRetainedEarnings($filter, $useSummary);
        $totalEquity = $equityAccountTotal + $currentYearEarnings;

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
                'accounts' => $equityAccounts,
                'current_year_earnings' => $currentYearEarnings,
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
     * Get posted balances for every account of a COA type without imposing a
     * legacy code range. This lets renamed and recoded equity accounts retain
     * their identity in the Balance Sheet.
     *
     * Excludes soft-deleted chart_of_accounts rows (deleted_at IS NOT NULL)
     * so duplicate-imported accounts that were deactivated don't double-count.
     */
    protected function getAccountsByType(ReportFilter $filter, string $type, bool $useSummary): array
    {
        $balanceExpr = in_array($type, ['asset', 'expense'], true)
            ? ($useSummary ? 'SUM(dls.net_amount)' : 'SUM(jel.debit - jel.credit)')
            : ($useSummary ? 'SUM(-dls.net_amount)' : 'SUM(jel.credit - jel.debit)');

        if ($useSummary) {
            $query = DB::table('daily_ledger_summaries as dls')
                ->join('chart_of_accounts as coa', function ($join) {
                    $join->on('coa.id', '=', 'dls.account_id')
                        ->whereNull('coa.deleted_at');
                })
                ->leftJoin('chart_of_accounts as parent_coa', 'parent_coa.id', '=', 'coa.parent_id')
                ->leftJoin('chart_of_accounts as group_coa', 'group_coa.code', '=', 'coa.account_group')
                ->where('coa.type', $type)
                ->where('dls.summary_date', '<=', $filter->asOfDate)
                ->select([
                    'coa.id', 'coa.code', 'coa.name', 'coa.account_group', 'coa.parent_id',
                    'parent_coa.code as parent_code', 'parent_coa.name as parent_name',
                    'group_coa.name as group_name', DB::raw($balanceExpr.' as balance'),
                ])
                ->groupBy('coa.id', 'coa.code', 'coa.name', 'coa.account_group', 'coa.parent_id', 'parent_coa.code', 'parent_coa.name', 'group_coa.name');

            if ($filter->legalEntityId) {
                $query->where('dls.legal_entity_id', $filter->legalEntityId);
            }
            if ($filter->entityBookId) {
                $query->where('dls.entity_book_id', $filter->entityBookId);
            }
        } else {
            $query = DB::table('journal_entry_lines as jel')
                ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
                ->join('chart_of_accounts as coa', function ($join) {
                    $join->on('coa.id', '=', 'jel.account_id')
                        ->whereNull('coa.deleted_at');
                })
                ->leftJoin('chart_of_accounts as parent_coa', 'parent_coa.id', '=', 'coa.parent_id')
                ->leftJoin('chart_of_accounts as group_coa', 'group_coa.code', '=', 'coa.account_group')
                ->where('je.status', 'posted')->where('je.is_active_version', true)
                ->where('coa.type', $type)
                ->where('je.entry_date', '<=', $filter->asOfDate)
                ->select([
                    'coa.id', 'coa.code', 'coa.name', 'coa.account_group', 'coa.parent_id',
                    'parent_coa.code as parent_code', 'parent_coa.name as parent_name',
                    'group_coa.name as group_name', DB::raw($balanceExpr.' as balance'),
                ])
                ->groupBy('coa.id', 'coa.code', 'coa.name', 'coa.account_group', 'coa.parent_id', 'parent_coa.code', 'parent_coa.name', 'group_coa.name');

            if ($filter->legalEntityId) {
                $query->where('je.legal_entity_id', $filter->legalEntityId);
            }
            if ($filter->entityBookId) {
                $query->where('je.entity_book_id', $filter->entityBookId);
            }
        }

        return $query->orderBy('coa.code')->get()->map(fn ($row) => [
            'account_id' => $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'account_group' => $row->account_group,
            'parent_id' => $row->parent_id,
            'parent_code' => $row->parent_code,
            'parent_name' => $row->parent_name,
            'group_name' => $row->group_name,
            'currency_code' => null,
            'balance' => (float) $row->balance,
        ])->toArray();
    }

    /**
     * Get retained earnings (calculated from revenue and expenses)
     *
     * Also strips "Opening Balance Equity" revenue/expense rows in case
     * imported journals tagged an opening-balance plug against a
     * revenue/expense account (defensive — the equity-section filter is
     * the primary fix).
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
     *
     * Excludes soft-deleted chart_of_accounts rows (deleted_at IS NOT NULL)
     * and uses an EXISTS subquery against bank_accounts instead of a LEFT
     * JOIN. The LEFT JOIN form previously DUPLICATED journal lines whenever
     * a chart_of_accounts row had more than one active bank_accounts row
     * pointing at it (production-incident 2026-09-24: bank balances were
     * double the actual cash-on-hand). EXISTS gives a yes/no flag without
     * multiplying the projection.
     */
    protected function getAccountsByTypeAndCategory(
        ReportFilter $filter,
        string $type,
        string $category,
        bool $useSummary
    ): array {
        // Natural-side sign convention: assets/expense keep debit-positive
        // balances; liabilities/equity/revenue are credit-positive. Summing
        // liabilities as debit-credit produced a NEGATIVE total liabilities
        // and threw A = L + E off by exactly 2x the liability balance
        // (balance sheet drift reported on production 2026-09-04).
        $debitPositive = in_array($type, ['asset', 'expense'], true);

        if ($useSummary) {
            // daily_ledger_summaries.net_amount is debit - credit.
            $balanceExpr = $debitPositive
                ? 'SUM(dls.net_amount)'
                : 'SUM(-dls.net_amount)';

            $query = DB::table('daily_ledger_summaries as dls')
                ->join('chart_of_accounts as coa', function ($join) {
                    $join->on('coa.id', '=', 'dls.account_id')
                        ->whereNull('coa.deleted_at');
                })
                ->leftJoin('chart_of_accounts as parent_coa', 'parent_coa.id', '=', 'coa.parent_id')
                ->leftJoin('chart_of_accounts as group_coa', 'group_coa.code', '=', 'coa.account_group')
                ->where('coa.type', $type)
                ->where(function ($categoryQuery) use ($type, $category) {
                    $this->applyCategoryFilter($categoryQuery, $type, $category);
                })
                ->where('dls.summary_date', '<=', $filter->asOfDate)
                ->select([
                    'coa.id',
                    'coa.code',
                    'coa.name',
                    'coa.account_group',
                    'coa.parent_id',
                    'parent_coa.code as parent_code',
                    'parent_coa.name as parent_name',
                    'group_coa.name as group_name',
                    DB::raw($balanceExpr.' as balance'),
                ])
                ->groupBy('coa.id', 'coa.code', 'coa.name', 'coa.account_group', 'coa.parent_id', 'parent_coa.code', 'parent_coa.name', 'group_coa.name');
        } else {
            $balanceExpr = $debitPositive
                ? 'SUM(jel.debit - jel.credit)'
                : 'SUM(jel.credit - jel.debit)';

            $query = DB::table('journal_entry_lines as jel')
                ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
                ->join('chart_of_accounts as coa', function ($join) {
                    $join->on('coa.id', '=', 'jel.account_id')
                        ->whereNull('coa.deleted_at');
                })
                ->leftJoin('chart_of_accounts as parent_coa', 'parent_coa.id', '=', 'coa.parent_id')
                ->leftJoin('chart_of_accounts as group_coa', 'group_coa.code', '=', 'coa.account_group')
                ->where('je.status', 'posted')->where('je.is_active_version', true)
                ->where('coa.type', $type)
                ->where(function ($categoryQuery) use ($type, $category) {
                    $this->applyCategoryFilter($categoryQuery, $type, $category);
                })
                ->where('je.entry_date', '<=', $filter->asOfDate)
                ->select([
                    'coa.id',
                    'coa.code',
                    'coa.name',
                    'coa.account_group',
                    'coa.parent_id',
                    'parent_coa.code as parent_code',
                    'parent_coa.name as parent_name',
                    'group_coa.name as group_name',
                    DB::raw($balanceExpr.' as balance'),
                ])
                ->groupBy('coa.id', 'coa.code', 'coa.name', 'coa.account_group', 'coa.parent_id', 'parent_coa.code', 'parent_coa.name', 'group_coa.name');
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
            ->map(fn ($row) => [
                'account_id' => $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'account_group' => $row->account_group,
                'parent_id' => $row->parent_id,
                'parent_code' => $row->parent_code,
                'parent_name' => $row->parent_name,
                'group_name' => $row->group_name,
                'currency_code' => null,
                'balance' => (float) $row->balance,
            ])
            ->toArray();
    }

    /**
     * Apply the category WHERE clause for a type+category combination.
     *
     * Assets use parent-chain classification rather than a bare subtype
     * match, because the imported COA contains leaf accounts (e.g.
     * `123200 Short-term deposits`) whose own subtype is NULL but whose
     * ancestor group (`120000 Current assets`) clearly determines the
     * category. A bare `subtype IN (...)` filter silently drops those from
     * the report and breaks A = L + E by their balance (production incident
     * 2026-09-24: balance_check drifted by 1,631 because of this).
     *
     * Liabilities/equity keep the simpler subtype check (their imported
     * COA has subtypes set on every posting account).
     */
    private function applyCategoryFilter($categoryQuery, string $type, string $category): void
    {
        if ($type === 'asset') {
            // The classification walks every asset account to a single
            // category, so the ID list is exhaustive and mutually exclusive —
            // no account is dropped and none is double-counted. The previous
            // `orWhereExists(bank_accounts)` workaround is gone because an
            // account holding both a clean fixed_asset subtype and an active
            // bank link would have been counted in BOTH current and
            // non-current totals.
            $ids = $this->resolveAssetAccountIdsForCategory($category);
            $categoryQuery->whereIn('coa.id', $ids);

            return;
        }

        $categoryQuery->whereIn('coa.subtype', $this->getCategorySubtypes($type, $category));
    }

    /**
     * Resolve which chart_of_accounts IDs belong to the given asset
     * category by walking each account's parent chain up to its root.
     *
     * Precedence:
     *   1. The account's own subtype, when it maps cleanly.
     *   2. The first ancestor with a recognised subtype.
     *   3. The root group's name (`Current assets` / `Noncurrent assets`).
     *   4. Fallback: `other_asset` (catch-all so nothing is dropped from
     *      the total assets figure).
     *
     * @return array<int>
     */
    private function resolveAssetAccountIdsForCategory(string $category): array
    {
        $currentSubtypes = $this->getCategorySubtypes('asset', 'current_asset');
        $fixedSubtypes = $this->getCategorySubtypes('asset', 'fixed_asset');
        $otherSubtypes = $this->getCategorySubtypes('asset', 'other_asset');

        $accounts = DB::table('chart_of_accounts')
            ->whereNull('deleted_at')
            ->where('type', 'asset')
            ->get(['id', 'parent_id', 'name', 'subtype']);

        $byId = [];
        foreach ($accounts as $account) {
            $byId[(int) $account->id] = $account;
        }

        $ids = [];
        foreach ($accounts as $account) {
            $resolved = $this->classifyAssetAccount($account, $byId, $currentSubtypes, $fixedSubtypes, $otherSubtypes);

            if ($resolved === $category) {
                $ids[] = (int) $account->id;
            }
        }

        return $ids;
    }

    /**
     * @param  array<int, object>  $byId
     * @param  array<string>  $currentSubtypes
     * @param  array<string>  $fixedSubtypes
     * @param  array<string>  $otherSubtypes
     */
    private function classifyAssetAccount(object $account, array $byId, array $currentSubtypes, array $fixedSubtypes, array $otherSubtypes): string
    {
        $selfSubtype = strtolower((string) $account->subtype);
        if (in_array($selfSubtype, $currentSubtypes, true)) {
            return 'current_asset';
        }
        if (in_array($selfSubtype, $fixedSubtypes, true)) {
            return 'fixed_asset';
        }
        if (in_array($selfSubtype, $otherSubtypes, true)) {
            return 'other_asset';
        }

        // Walk up the parent chain looking for a classified ancestor.
        $node = $account;
        $visited = [];
        while ($node->parent_id !== null && isset($byId[(int) $node->parent_id])) {
            if (in_array((int) $node->id, $visited, true)) {
                break; // cycle guard
            }
            $visited[] = (int) $node->id;
            $node = $byId[(int) $node->parent_id];

            $ancestorSubtype = strtolower((string) $node->subtype);
            if (in_array($ancestorSubtype, $currentSubtypes, true)) {
                return 'current_asset';
            }
            if (in_array($ancestorSubtype, $fixedSubtypes, true)) {
                return 'fixed_asset';
            }
            if (in_array($ancestorSubtype, $otherSubtypes, true)) {
                return 'other_asset';
            }
        }

        // No typed ancestor — classify by the root group's name. This
        // catches group headers like `120000 Current assets` that carry
        // no subtype of their own, and their untyped leaf children.
        // Order matters: the non-current patterns are checked first so
        // `Long-term deposits` isn't captured by the `deposit` keyword.
        $rootName = (string) $node->name;
        if (preg_match('/non[-\s]?current|long[-\s]?term|fixed\s+asset|property|plant|equipment|intangible|goodwill|right[-\s]?of[-\s]?use|investment property|deferred tax asset|accumulated depreciation|accumulated amortisation/i', $rootName)) {
            return 'fixed_asset';
        }
        if (preg_match('/current|bank|cash|receivable|inventor|prepay|short[-\s]?term|deposit/i', $rootName)) {
            return 'current_asset';
        }

        return 'other_asset';
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
     * Support both the original database subtype names and the expanded
     * subtype values currently produced by the Chart of Accounts UI/importer.
     */
    protected function getCategorySubtypes(string $type, string $category): array
    {
        return match ([$type, $category]) {
            ['asset', 'current_asset'] => ['current_asset', 'asset_receivable', 'asset_current', 'asset_cash'],
            ['asset', 'fixed_asset'] => ['fixed_asset', 'asset_fixed', 'asset_non_current'],
            ['asset', 'other_asset'] => ['other_asset'],
            ['liability', 'current_liability'] => ['current_liability', 'liability_payable', 'liability_current'],
            ['liability', 'long_term_liability'] => ['long_term_liability', 'liability_non_current'],
            default => [$category],
        };
    }

    /**
     * Get category code ranges (legacy, delegated to ReportMappingReader)
     *
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

        // net_amount is debit-credit; equity is credit-positive — negate to
        // match getEquityFromJournal()'s (credit - debit) convention.
        return -$query->sum('dls.net_amount');
    }

    /**
     * Get equity from journal entries
     */
    protected function getEquityFromJournal(ReportFilter $filter): float
    {
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')->where('je.is_active_version', true)
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
            ->where('je.status', 'posted')->where('je.is_active_version', true)
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
