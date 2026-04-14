<?php

namespace Modules\FinancialReporting\Services\Cache;

use Modules\FinancialReporting\Models\ReportCache;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\DTOs\ReportData;
use Illuminate\Support\Facades\Log;

class ReportCacheService
{
    protected bool $enabled;
    protected int $defaultTtl;
    protected array $tags;

    public function __construct()
    {
        $this->enabled = config('financialreporting.cache.enabled', true);
        $this->defaultTtl = config('financialreporting.cache.ttl', 3600);
        $this->tags = config('financialreporting.cache.tags', []);
    }

    /**
     * Get cached report or execute callback
     */
    public function remember(
        string $reportType,
        ReportFilter $filter,
        callable $callback,
        ?int $ttl = null,
        ?int $userId = null
    ): ReportData {
        if (!$this->enabled) {
            return $callback();
        }

        $cacheKey = $this->generateCacheKey($reportType, $filter);
        
        // Try to get from cache — scoped to current tenant
        $tenantId = $this->currentTenantId();
        $cached = ReportCache::getCached($cacheKey, $tenantId);
        
        if ($cached && $cached->isValid()) {
            Log::info("Report cache hit", [
                'report_type' => $reportType,
                'cache_key' => $cacheKey,
            ]);
            
            return $this->unserializeReportData($cached->getData());
        }

        // Cache miss - execute callback
        Log::info("Report cache miss", [
            'report_type' => $reportType,
            'cache_key' => $cacheKey,
        ]);

        $reportData = $callback();
        
        // Store in cache
        $this->store(
            $reportType,
            $filter,
            $reportData,
            $ttl ?? $this->defaultTtl,
            $userId
        );

        return $reportData;
    }

    /**
     * Store report in cache
     */
    public function store(
        string $reportType,
        ReportFilter $filter,
        ReportData $reportData,
        int $ttl,
        ?int $userId = null
    ): ReportCache {
        $cacheKey = $this->generateCacheKey($reportType, $filter);
        
        $metadata = [
            'generated_at' => now()->toIso8601String(),
            'ttl' => $ttl,
            'filter_summary' => $this->getFilterSummary($filter),
        ];

        return ReportCache::store(
            cacheKey: $cacheKey,
            reportType: $reportType,
            filters: $this->serializeFilter($filter),
            data: $this->serializeReportData($reportData),
            ttl: $ttl,
            userId: $userId,
            metadata: $metadata,
            tenantId: $this->currentTenantId(),
        );
    }

    /**
     * Generate cache key from report type, filters, and tenant identity.
     *
     * The tenant ID is prepended to prevent cross-tenant cache collisions:
     * two tenants producing the same filters + report_type would previously
     * produce the same MD5 and overwrite each other's cached report.
     */
    public function generateCacheKey(string $reportType, ReportFilter $filter): string
    {
        $tenantId = $this->currentTenantId() ?? 'central';

        $parts = [
            $tenantId,
            $reportType,
            $filter->startDate?->format('Y-m-d'),
            $filter->endDate?->format('Y-m-d'),
            $filter->asOfDate?->format('Y-m-d'),
            $filter->compareStartDate?->format('Y-m-d'),
            $filter->compareEndDate?->format('Y-m-d'),
            $filter->accountId,
            $filter->accountType,
            $filter->departmentId,
            $filter->projectId,
            $filter->locationId,
            $filter->customerId,
            $filter->vendorId,
            $filter->productId,
            $filter->categoryId,
            $filter->interval,
        ];

        $keyString = implode(':', array_filter($parts, fn($v) => $v !== null));

        return md5($keyString);
    }

    /**
     * Invalidate cache for specific report type
     */
    public function invalidate(string $reportType): int
    {
        Log::info("Invalidating cache for report type", ['report_type' => $reportType]);
        
        return ReportCache::clearByReportType($reportType);
    }

    /**
     * Invalidate all report caches
     */
    public function invalidateAll(): int
    {
        Log::info("Invalidating all report caches");
        
        return ReportCache::clearAll();
    }

    /**
     * Clear expired cache entries
     */
    public function clearExpired(): int
    {
        $count = ReportCache::clearExpired();
        
        Log::info("Cleared expired cache entries", ['count' => $count]);
        
        return $count;
    }

    /**
     * Get cache statistics
     */
    public function getStatistics(): array
    {
        $total = ReportCache::count();
        $valid = ReportCache::valid()->count();
        $expired = ReportCache::expired()->count();
        
        $byType = ReportCache::query()
            ->selectRaw('report_type, COUNT(*) as count, SUM(hit_count) as total_hits')
            ->groupBy('report_type')
            ->get()
            ->keyBy('report_type')
            ->toArray();

        $topHits = ReportCache::valid()
            ->orderBy('hit_count', 'desc')
            ->limit(10)
            ->get(['cache_key', 'report_type', 'hit_count', 'last_accessed_at'])
            ->toArray();

        return [
            'total_entries' => $total,
            'valid_entries' => $valid,
            'expired_entries' => $expired,
            'by_type' => $byType,
            'top_hits' => $topHits,
            'cache_enabled' => $this->enabled,
            'default_ttl' => $this->defaultTtl,
        ];
    }

    /**
     * Warm cache for common reports
     */
    public function warmCache(array $reportConfigs): array
    {
        $results = [];

        foreach ($reportConfigs as $config) {
            try {
                $reportType = $config['report_type'];
                $filter = ReportFilter::fromRequest($config['filters'] ?? []);
                $callback = $config['callback'];
                $ttl = $config['ttl'] ?? $this->defaultTtl;

                $cacheKey = $this->generateCacheKey($reportType, $filter);
                
                // Check if already cached
                $cached = ReportCache::getCached($cacheKey);
                if ($cached && $cached->isValid()) {
                    $results[$reportType] = [
                        'status' => 'already_cached',
                        'cache_key' => $cacheKey,
                    ];
                    continue;
                }

                // Generate and cache
                $reportData = $callback();
                $this->store($reportType, $filter, $reportData, $ttl);

                $results[$reportType] = [
                    'status' => 'cached',
                    'cache_key' => $cacheKey,
                ];

                Log::info("Cache warmed for report", [
                    'report_type' => $reportType,
                    'cache_key' => $cacheKey,
                ]);

            } catch (\Exception $e) {
                $results[$config['report_type']] = [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];

                Log::error("Failed to warm cache for report", [
                    'report_type' => $config['report_type'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Check if cache is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enable cache
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Disable cache
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Serialize ReportData for storage
     */
    protected function serializeReportData(ReportData $reportData): array
    {
        return $reportData->toArray();
    }

    /**
     * Unserialize ReportData from storage
     */
    protected function unserializeReportData(array $data): ReportData
    {
        return new ReportData(
            title: $data['title'],
            data: $data['data'],
            summary: $data['summary'] ?? [],
            metadata: $data['metadata'] ?? [],
            comparison: $data['comparison'] ?? null
        );
    }

    /**
     * Serialize ReportFilter for storage
     */
    protected function serializeFilter(ReportFilter $filter): array
    {
        return [
            'start_date' => $filter->startDate?->format('Y-m-d'),
            'end_date' => $filter->endDate?->format('Y-m-d'),
            'as_of_date' => $filter->asOfDate?->format('Y-m-d'),
            'compare_start_date' => $filter->compareStartDate?->format('Y-m-d'),
            'compare_end_date' => $filter->compareEndDate?->format('Y-m-d'),
            'account_id' => $filter->accountId,
            'account_type' => $filter->accountType,
            'department_id' => $filter->departmentId,
            'project_id' => $filter->projectId,
            'location_id' => $filter->locationId,
            'customer_id' => $filter->customerId,
            'vendor_id' => $filter->vendorId,
            'product_id' => $filter->productId,
            'category_id' => $filter->categoryId,
            'interval' => $filter->interval,
        ];
    }

    /**
     * Get filter summary for metadata
     */
    protected function getFilterSummary(ReportFilter $filter): array
    {
        $summary = [];

        if ($filter->startDate && $filter->endDate) {
            $summary['date_range'] = $filter->startDate->format('Y-m-d') . ' to ' . $filter->endDate->format('Y-m-d');
        }

        if ($filter->asOfDate) {
            $summary['as_of_date'] = $filter->asOfDate->format('Y-m-d');
        }

        if ($filter->accountType) {
            $summary['account_type'] = $filter->accountType;
        }

        if ($filter->departmentId) {
            $summary['department_id'] = $filter->departmentId;
        }

        return $summary;
    }

    /**
     * Resolve the current tenant ID from the tenancy runtime.
     * Returns null when no tenant context is active (central admin, CLI).
     */
    private function currentTenantId(): ?string
    {
        if (function_exists('tenant') && tenant()) {
            return (string) tenant('id');
        }

        return null;
    }
}
