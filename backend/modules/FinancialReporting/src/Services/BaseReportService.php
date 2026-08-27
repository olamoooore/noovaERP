<?php

namespace Modules\FinancialReporting\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\FinancialReporting\DTOs\ReportFilter;

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
        if (! $this->cacheEnabled) {
            return $callback();
        }

        $fullKey = $this->cachePrefix.$key;

        return Cache::tags(config('financialreporting.cache.tags', []))
            ->remember($fullKey, $this->cacheTtl, $callback);
    }

    /**
     * Clear cache for this report
     */
    protected function clearCache(string $key): void
    {
        $fullKey = $this->cachePrefix.$key;
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
     *
     * Workstream 2 (2026-04-15): Added validation to ensure summary data exists
     * and is fresh. Prevents async reporting drift caused by missing/outdated summaries.
     */
    protected function shouldUseSummary(Carbon $date): bool
    {
        $daysThreshold = config('financialreporting.reports.use_summary_after_days', 7);

        // If date is not old enough, always use direct query
        if ($date->diffInDays(now()) <= $daysThreshold) {
            return false;
        }

        // For older dates, verify summary data exists and is fresh
        return $this->isSummaryDataFresh($date);
    }

    /**
     * Check if summary data is fresh and complete for the given date.
     *
     * Workstream 2 (2026-04-15): Prevents async reporting drift by validating
     * that summary jobs have completed before using summary data.
     */
    protected function isSummaryDataFresh(Carbon $date): bool
    {
        // Quick check: if summary table is globally empty, never use it
        $hasAnySummaries = DB::table('daily_ledger_summaries')->exists();
        if (! $hasAnySummaries) {
            return false;
        }

        // Check if any journal entries exist for this date
        $journalEntriesExist = DB::table('journal_entries')
            ->where('entry_date', $date->format('Y-m-d'))
            ->where('status', 'posted')
            ->exists();

        // If no journal entries, summary is not needed (fresh by definition)
        if (! $journalEntriesExist) {
            return true;
        }

        // Check if summary exists for this date
        $summaryExists = DB::table('daily_ledger_summaries')
            ->where('summary_date', $date->format('Y-m-d'))
            ->exists();

        if (! $summaryExists) {
            Log::warning("Summary data missing for date {$date->format('Y-m-d')}, falling back to direct query");

            return false;
        }

        // Verify summary count matches journal entry line count
        // This catches cases where summary job failed partway through
        $summaryLineCount = DB::table('daily_ledger_summaries')
            ->where('summary_date', $date->format('Y-m-d'))
            ->sum('entry_count');

        $journalLineCount = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.entry_date', $date->format('Y-m-d'))
            ->where('journal_entries.status', 'posted')
            ->count();

        if ($summaryLineCount !== $journalLineCount) {
            Log::warning("Summary data incomplete for date {$date->format('Y-m-d')}. ".
                "Summary lines: {$summaryLineCount}, Journal lines: {$journalLineCount}. ".
                'Falling back to direct query.');

            return false;
        }

        return true;
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
            ->where('je.is_active_version', true) // exclude reversed/replaced entries
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

        // Apply entity filters
        if ($filter->legalEntityId) {
            $query->where('je.legal_entity_id', $filter->legalEntityId);
        }
        if ($filter->entityBookId) {
            $query->where('je.entity_book_id', $filter->entityBookId);
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

        // Apply entity filters (summary table uses legal_entity_id if present)
        if ($filter->legalEntityId) {
            $query->where('dls.legal_entity_id', $filter->legalEntityId);
        }
        if ($filter->entityBookId) {
            $query->where('dls.entity_book_id', $filter->entityBookId);
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
