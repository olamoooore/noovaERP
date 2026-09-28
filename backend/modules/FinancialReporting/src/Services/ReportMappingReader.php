<?php

namespace Modules\FinancialReporting\Services;

use Illuminate\Support\Facades\DB;

/**
 * Resolves chart-of-account IDs for a given report tag using the
 * report_tags → account_report_mappings → master_coa_nodes → entity_account_mappings
 * chain.  Falls back to hardcoded code ranges when REPORT_CLASSIFICATION=legacy.
 */
class ReportMappingReader
{
    /**
     * Semantic fallback used while a tenant has not yet configured a report
     * tag through the master-COA mapping tables.
     *
     * Explicit mappings remain authoritative as soon as at least one account
     * is mapped to the requested tag.
     */
    private const FALLBACK_SUBTYPES = [
        'PL_COGS' => ['cost_of_goods_sold', 'expense_direct_cost'],
        'PL_OPERATING_EXPENSES' => ['operating_expense', 'expense', 'expense_depreciation'],
        'PL_OTHER_INCOME' => ['other_revenue', 'income_other'],
        'PL_OTHER_EXPENSES' => ['other_expense'],
    ];

    /**
     * Feature flag: 'mapped' uses report_tags tables, 'legacy' uses code ranges.
     */
    public function isMapped(): bool
    {
        return config('app.report_classification', env('REPORT_CLASSIFICATION', 'mapped')) === 'mapped';
    }

    // ─── Tag-based resolution ───────────────────────────────────────────

    /**
     * Return entity chart_of_accounts.id list for a given report tag code.
     * Chain: report_tags → account_report_mappings → master_coa_nodes → entity_account_mappings → chart_of_accounts
     */
    public function accountIdsForTag(string $tagCode, ?int $legalEntityId = null): array
    {
        $query = DB::table('report_tags as rt')
            ->join('account_report_mappings as arm', 'arm.report_tag_id', '=', 'rt.id')
            ->join('master_coa_nodes as mcn', 'mcn.id', '=', 'arm.master_coa_node_id')
            ->join('entity_account_mappings as eam', 'eam.master_coa_node_id', '=', 'mcn.id')
            ->where('rt.code', $tagCode)
            ->where('arm.is_active', true);

        if ($legalEntityId) {
            $query->where('eam.legal_entity_id', $legalEntityId);
        }

        return $query->pluck('eam.chart_of_account_id')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Return entity chart_of_accounts.id list for multiple tags at once.
     */
    public function accountIdsForTags(array $tagCodes, ?int $legalEntityId = null): array
    {
        $query = DB::table('report_tags as rt')
            ->join('account_report_mappings as arm', 'arm.report_tag_id', '=', 'rt.id')
            ->join('master_coa_nodes as mcn', 'mcn.id', '=', 'arm.master_coa_node_id')
            ->join('entity_account_mappings as eam', 'eam.master_coa_node_id', '=', 'mcn.id')
            ->whereIn('rt.code', $tagCodes)
            ->where('arm.is_active', true);

        if ($legalEntityId) {
            $query->where('eam.legal_entity_id', $legalEntityId);
        }

        return $query->pluck('eam.chart_of_account_id')
            ->unique()
            ->values()
            ->toArray();
    }

    // ─── Legacy code-range constants ────────────────────────────────────

    /**
     * Map from report section key → legacy code-range string(s).
     * Used when REPORT_CLASSIFICATION=legacy.
     */
    public const LEGACY_RANGES = [
        // P&L
        'PL_COGS' => ['5000-5099'],
        'PL_OPERATING_EXPENSES' => ['5100-5199'],
        'PL_OTHER_INCOME' => ['4100-4999'],
        'PL_OTHER_EXPENSES' => ['5200-5999'],
        'PL_REVENUE' => null,           // uses coa.type = 'revenue' (no code filter)

        // Balance Sheet
        'BS_CURRENT_ASSETS' => ['1000-1299'],
        'BS_NON_CURRENT_ASSETS' => ['1300-1899'],
        'BS_OTHER_ASSETS' => ['1900-1999'],
        'BS_CURRENT_LIABILITIES' => ['2000-2199'],
        'BS_NON_CURRENT_LIABILITIES' => ['2200-2999'],
        'BS_EQUITY' => ['3000-3099'],

        // Cash Flow (finer grained)
        'CF_CASH' => ['1000-1099'],
        'CF_AR' => ['1100-1199'],
        'CF_INVENTORY' => ['1200-1299'],
        'CF_AP' => ['2000-2099'],
        'CF_FIXED_ASSETS' => ['1300-1899'],
        'CF_LONG_TERM_LIABILITIES' => ['2100-2999'],
        'CF_CAPITAL' => ['3000-3099'],
        'CF_DRAWINGS' => ['3200-3299'],
    ];

    // ─── Unified query helpers ──────────────────────────────────────────

    /**
     * Apply either tag-based or code-range WHERE clause to a query builder.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $tagCode  Report tag code, e.g. 'PL_COGS'
     * @param  string  $coaAlias  Table alias for chart_of_accounts (default 'coa')
     */
    public function applyFilter($query, string $tagCode, string $coaAlias = 'coa', ?int $legalEntityId = null): void
    {
        if ($this->isMapped()) {
            $ids = $this->accountIdsForTag($tagCode, $legalEntityId);
            if (empty($ids)) {
                $subtypes = self::FALLBACK_SUBTYPES[$tagCode] ?? [];

                if (empty($subtypes)) {
                    $query->whereRaw('1=0');
                } else {
                    $query->whereIn("{$coaAlias}.subtype", $subtypes);
                }
            } else {
                $query->whereIn("{$coaAlias}.id", $ids);
            }

            return;
        }

        // Legacy: use code ranges
        $ranges = self::LEGACY_RANGES[$tagCode] ?? null;
        if ($ranges === null) {
            return; // no code filter (e.g. PL_REVENUE uses type filter only)
        }

        $query->where(function ($q) use ($ranges, $coaAlias, $tagCode) {
            foreach ($ranges as $range) {
                [$start, $end] = explode('-', $range);
                $q->orWhereBetween("{$coaAlias}.code", [$start, $end]);
            }

            // Lossless catch-all: expense accounts outside every explicit P&L
            // band (e.g. 6-digit charts where administrative expenses live at
            // 6xxxxx) still belong to Operating Expenses. Dropping them
            // understates costs and overstates profit. Callers constrain
            // coa.type = 'expense', so this cannot pull in non-expense rows.
            if ($tagCode === 'PL_OPERATING_EXPENSES') {
                $outsideRanges = array_merge(
                    self::LEGACY_RANGES['PL_COGS'] ?? [],
                    self::LEGACY_RANGES['PL_OTHER_EXPENSES'] ?? [],
                );

                if ($outsideRanges !== []) {
                    $q->orWhere(function ($catchAll) use ($outsideRanges, $coaAlias) {
                        foreach ($outsideRanges as $range) {
                            [$start, $end] = explode('-', $range);
                            $catchAll->whereNotBetween("{$coaAlias}.code", [$start, $end]);
                        }
                    });
                }
            }
        });
    }

    /**
     * Apply filter for a balance-based query (code range with start/end codes).
     * Used by CashFlowService helpers that take explicit start/end codes.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    public function applyBalanceFilter($query, string $tagCode, string $coaAlias = 'coa'): void
    {
        $this->applyFilter($query, $tagCode, $coaAlias);
    }

    /**
     * Return the legacy code range start/end for a tag (used by methods that
     * call getAccountBalanceChange with explicit start/end strings).
     * Returns [start, end] or null if mapped.
     */
    public function getCodeRange(string $tagCode): ?array
    {
        if ($this->isMapped()) {
            return null; // caller must use accountIdsForTag instead
        }

        $ranges = self::LEGACY_RANGES[$tagCode] ?? null;
        if ($ranges && count($ranges) === 1) {
            return explode('-', $ranges[0]);
        }

        return null;
    }

    /**
     * Return account IDs for a tag. In mapped mode, uses tag chain.
     * In legacy mode, queries chart_of_accounts by code range.
     */
    public function resolveAccountIds(string $tagCode, ?int $legalEntityId = null): array
    {
        if ($this->isMapped()) {
            return $this->accountIdsForTag($tagCode, $legalEntityId);
        }

        $ranges = self::LEGACY_RANGES[$tagCode] ?? null;
        if ($ranges === null) {
            return [];
        }

        $query = DB::table('chart_of_accounts');
        $query->where(function ($q) use ($ranges, $tagCode) {
            foreach ($ranges as $range) {
                [$start, $end] = explode('-', $range);
                $q->orWhereBetween('code', [$start, $end]);
            }

            // Same lossless catch-all as applyFilter(), but the id list has no
            // caller-supplied type filter — constrain to expenses explicitly.
            if ($tagCode === 'PL_OPERATING_EXPENSES') {
                $outsideRanges = array_merge(
                    self::LEGACY_RANGES['PL_COGS'] ?? [],
                    self::LEGACY_RANGES['PL_OTHER_EXPENSES'] ?? [],
                );

                if ($outsideRanges !== []) {
                    $q->orWhere(function ($catchAll) use ($outsideRanges) {
                        $catchAll->where('type', 'expense');
                        foreach ($outsideRanges as $range) {
                            [$start, $end] = explode('-', $range);
                            $catchAll->whereNotBetween('code', [$start, $end]);
                        }
                    });
                }
            }
        });

        return $query->pluck('id')->toArray();
    }
}
