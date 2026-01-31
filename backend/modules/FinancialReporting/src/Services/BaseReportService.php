<?php

namespace Modules\FinancialReporting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Carbon\Carbon;

abstract class BaseReportService
{
    protected bool $cacheEnabled;
    protected int $cacheTtl;
    protected string $cachePrefix;

    public function __construct()
    {
        $this->cacheEnabled = config('financialreporting.cache.enabled', true);
        $this->cacheTtl = config('financialreporting.cache.ttl', 3600);
        $this->cachePrefix = config('financialreporting.cache.prefix', 'financial_report:');
    }

    /**
     * Get data from cache or execute query
     */
    protected function remember(string $key, callable $callback): mixed
    {
        if (!$this->cacheEnabled) {
            return $callback();
        }

        $fullKey = $this->cachePrefix . $key;
        
        return Cache::tags(config('financialreporting.cache.tags', []))
            ->remember($fullKey, $this->cacheTtl, $callback);
    }

    /**
     * Clear cache for this report
     */
    protected function clearCache(string $key): void
    {
        $fullKey = $this->cachePrefix . $key;
        Cache::forget($fullKey);
    }

    /**
     * Clear all report caches
     */
    public static function clearAllCache(): void
    {
        Cache::tags(config('financialreporting.cache.tags', []))->flush();
    }

    /**
     * Determine if we should use summary table
     */
    protected function shouldUseSummary(Carbon $date): bool
    {
        $daysThreshold = config('financialreporting.reports.use_summary_after_days', 7);
        return $date->diffInDays(now()) > $daysThreshold;
    }

    /**
     * Get account balances from journal entries
     */
    protected function getAccountBalances(ReportFilter $filter): array
    {
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')
            ->select([
                'coa.id as account_id',
                'coa.code',
                'coa.name',
                'coa.type',
                DB::raw('SUM(jel.debit) as total_debit'),
                DB::raw('SUM(jel.credit) as total_credit'),
                DB::raw('SUM(jel.debit - jel.credit) as balance'),
            ])
            ->groupBy('coa.id', 'coa.code', 'coa.name', 'coa.type');

        // Apply date filters
        if ($filter->startDate) {
            $query->where('je.entry_date', '>=', $filter->startDate);
        }
        if ($filter->endDate) {
            $query->where('je.entry_date', '<=', $filter->endDate);
        }
        if ($filter->asOfDate) {
            $query->where('je.entry_date', '<=', $filter->asOfDate);
        }

        // Apply account filters
        if ($filter->accountId) {
            $query->where('coa.id', $filter->accountId);
        }
        if ($filter->accountType) {
            $query->where('coa.type', $filter->accountType);
        }

        // Apply dimension filters
        if ($filter->departmentId || $filter->projectId || $filter->locationId) {
            $query->where(function ($q) use ($filter) {
                if ($filter->departmentId) {
                    $q->whereJsonContains('jel.dimensions->department_id', $filter->departmentId);
                }
                if ($filter->projectId) {
                    $q->whereJsonContains('jel.dimensions->project_id', $filter->projectId);
                }
                if ($filter->locationId) {
                    $q->whereJsonContains('jel.dimensions->location_id', $filter->locationId);
                }
            });
        }

        return $query->get()->toArray();
    }

    /**
     * Get account balances from summary table
     */
    protected function getAccountBalancesFromSummary(ReportFilter $filter): array
    {
        $query = DB::table('daily_ledger_summaries as dls')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'dls.account_id')
            ->select([
                'coa.id as account_id',
                'coa.code',
                'coa.name',
                'coa.type',
                DB::raw('SUM(dls.total_debit) as total_debit'),
                DB::raw('SUM(dls.total_credit) as total_credit'),
                DB::raw('SUM(dls.net_amount) as balance'),
            ])
            ->groupBy('coa.id', 'coa.code', 'coa.name', 'coa.type');

        // Apply date filters
        if ($filter->startDate) {
            $query->where('dls.summary_date', '>=', $filter->startDate);
        }
        if ($filter->endDate) {
            $query->where('dls.summary_date', '<=', $filter->endDate);
        }
        if ($filter->asOfDate) {
            $query->where('dls.summary_date', '<=', $filter->asOfDate);
        }

        // Apply account filters
        if ($filter->accountId) {
            $query->where('coa.id', $filter->accountId);
        }
        if ($filter->accountType) {
            $query->where('coa.type', $filter->accountType);
        }

        return $query->get()->toArray();
    }

    /**
     * Format currency amount
     */
    protected function formatCurrency(float $amount): string
    {
        return number_format($amount, 2, '.', ',');
    }

    /**
     * Calculate percentage change
     */
    protected function calculatePercentageChange(float $current, float $previous): ?float
    {
        if ($previous == 0) {
            return null;
        }

        return (($current - $previous) / abs($previous)) * 100;
    }
}
