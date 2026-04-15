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
use Stancl\Tenancy\Facades\Tenancy;

/**
 * Job to clear expired report cache entries
 * Should be run daily via Laravel scheduler
 */
class ClearReportCache implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected bool $clearAll;
    protected ?string $tenantId;

    /**
     * Create a new job instance.
     *
     * @param bool $clearAll Clear all cache entries, not just expired ones
     * @param string|null $tenantId Specific tenant to clear (null = current context)
     */
    public function __construct(bool $clearAll = false, ?string $tenantId = null)
    {
        $this->clearAll = $clearAll;
        $this->tenantId = $tenantId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // If tenant ID provided, initialize tenancy for that tenant
            if ($this->tenantId) {
                Tenancy::initialize($this->tenantId);
            }

            $tenantId = $this->currentTenantId();
            
            Log::info('Starting report cache cleanup', [
                'clear_all' => $this->clearAll,
                'tenant_id' => $tenantId,
            ]);

            if ($this->clearAll) {
                $this->clearAllCache($tenantId);
            } else {
                $this->clearExpiredCache($tenantId);
            }

            Log::info('Report cache cleanup completed successfully', [
                'tenant_id' => $tenantId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to clear report cache', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => $this->tenantId,
            ]);

            throw $e;
        }
    }

    /**
     * Get current tenant ID from tenancy runtime
     */
    private function currentTenantId(): ?string
    {
        if (function_exists('tenant') && tenant()) {
            return (string) tenant('id');
        }
        return null;
    }

    /**
     * Clear all cache entries (tenant-scoped)
     */
    protected function clearAllCache(?string $tenantId): void
    {
        $query = ReportCache::query();
        
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        
        $count = $query->count();
        
        if ($tenantId) {
            ReportCache::where('tenant_id', $tenantId)->delete();
        } else {
            ReportCache::truncate();
        }

        // Clear Redis cache with report tags (only works with tag-supporting drivers)
        try {
            Cache::tags(['reports', 'dashboards', 'sales_analytics', 'inventory_reports'])->flush();
        } catch (\BadMethodCallException $e) {
            // Cache driver does not support tagging — skip
        }

        Log::info('Cleared all report cache', [
            'database_entries_cleared' => $count,
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * Clear only expired cache entries (tenant-scoped)
     */
    protected function clearExpiredCache(?string $tenantId): void
    {
        $now = Carbon::now();

        $query = ReportCache::where('expires_at', '<', $now);
        
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        
        $expiredCount = $query->count();
        $query->delete();

        Log::info('Cleared expired report cache', [
            'expired_entries_cleared' => $expiredCount,
            'tenant_id' => $tenantId,
        ]);

        // Note: Redis cache with TTL will expire automatically
        // We only need to clean up the database cache table
    }

    /**
     * Clear cache for specific report type (tenant-scoped)
     */
    public static function clearByReportType(string $reportType, ?string $tenantId = null): void
    {
        $query = ReportCache::where('report_type', $reportType);
        
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        
        $query->delete();
        
        // Clear Redis cache for this report type (only works with tag-supporting drivers)
        try {
            Cache::tags(['reports'])->flush();
        } catch (\BadMethodCallException $e) {
            // Cache driver does not support tagging — skip
        }

        Log::info('Cleared cache for report type', [
            'report_type' => $reportType,
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * Clear cache for specific date range (tenant-scoped)
     */
    public static function clearByDateRange(Carbon $startDate, Carbon $endDate, ?string $tenantId = null): void
    {
        $query = ReportCache::whereBetween('created_at', [$startDate, $endDate]);
        
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        
        $query->delete();

        Log::info('Cleared cache for date range', [
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'tenant_id' => $tenantId,
        ]);
    }
}
