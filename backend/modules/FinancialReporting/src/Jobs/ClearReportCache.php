<?php

namespace Modules\FinancialReporting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\FinancialReporting\Models\ReportCache;
use Carbon\Carbon;

/**
 * Job to clear expired report cache entries
 * Should be run daily via Laravel scheduler
 */
class ClearReportCache implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected bool $clearAll;

    /**
     * Create a new job instance.
     *
     * @param bool $clearAll Clear all cache entries, not just expired ones
     */
    public function __construct(bool $clearAll = false)
    {
        $this->clearAll = $clearAll;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Starting report cache cleanup', [
                'clear_all' => $this->clearAll,
            ]);

            if ($this->clearAll) {
                $this->clearAllCache();
            } else {
                $this->clearExpiredCache();
            }

            Log::info('Report cache cleanup completed successfully');
        } catch (\Exception $e) {
            Log::error('Failed to clear report cache', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Clear all cache entries
     */
    protected function clearAllCache(): void
    {
        // Clear database cache
        $count = ReportCache::count();
        ReportCache::truncate();

        // Clear Redis cache with report tags (only works with tag-supporting drivers)
        try {
            Cache::tags(['reports', 'dashboards', 'sales_analytics', 'inventory_reports'])->flush();
        } catch (\BadMethodCallException $e) {
            // Cache driver does not support tagging — skip
        }

        Log::info('Cleared all report cache', [
            'database_entries_cleared' => $count,
        ]);
    }

    /**
     * Clear only expired cache entries
     */
    protected function clearExpiredCache(): void
    {
        $now = Carbon::now();

        // Clear expired database cache
        $expiredCount = ReportCache::where('expires_at', '<', $now)->count();
        ReportCache::where('expires_at', '<', $now)->delete();

        Log::info('Cleared expired report cache', [
            'expired_entries_cleared' => $expiredCount,
        ]);

        // Note: Redis cache with TTL will expire automatically
        // We only need to clean up the database cache table
    }

    /**
     * Clear cache for specific report type
     */
    public static function clearByReportType(string $reportType): void
    {
        ReportCache::where('report_type', $reportType)->delete();
        
        // Clear Redis cache for this report type (only works with tag-supporting drivers)
        try {
            Cache::tags(['reports'])->flush();
        } catch (\BadMethodCallException $e) {
            // Cache driver does not support tagging — skip
        }

        Log::info('Cleared cache for report type', [
            'report_type' => $reportType,
        ]);
    }

    /**
     * Clear cache for specific date range
     */
    public static function clearByDateRange(Carbon $startDate, Carbon $endDate): void
    {
        // This is a simplified approach - in production you might want to
        // parse the parameters JSONB field to match date ranges
        ReportCache::whereBetween('created_at', [$startDate, $endDate])->delete();

        Log::info('Cleared cache for date range', [
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
        ]);
    }
}
