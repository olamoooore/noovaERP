<?php

namespace Modules\FinancialReporting\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\FinancialReporting\Jobs\ClearReportCache;
use Modules\FinancialReporting\Models\ReportCache;

/**
 * Cache Controller
 * Manages report cache operations
 */
class CacheController extends Controller
{
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
     * Get cache statistics (tenant-scoped)
     */
    public function stats(): JsonResponse
    {
        $tenantId = $this->currentTenantId();
        $baseQuery = $tenantId ? ReportCache::where('tenant_id', $tenantId) : ReportCache::query();

        $totalEntries = (clone $baseQuery)->count();
        $expiredEntries = (clone $baseQuery)->where('expires_at', '<', Carbon::now())->count();
        $activeEntries = $totalEntries - $expiredEntries;

        // Get cache by report type
        $byType = (clone $baseQuery)
            ->selectRaw('report_type, COUNT(*) as count')
            ->groupBy('report_type')
            ->get()
            ->pluck('count', 'report_type');

        // Get oldest and newest cache entries
        $oldest = (clone $baseQuery)->orderBy('created_at', 'asc')->first();
        $newest = (clone $baseQuery)->orderBy('created_at', 'desc')->first();

        return response()->json([
            'success' => true,
            'data' => [
                'total_entries' => $totalEntries,
                'active_entries' => $activeEntries,
                'expired_entries' => $expiredEntries,
                'by_type' => $byType,
                'oldest_entry' => $oldest ? $oldest->created_at : null,
                'newest_entry' => $newest ? $newest->created_at : null,
            ],
        ]);
    }

    /**
     * Clear all cache
     */
    public function clearAll(): JsonResponse
    {
        ClearReportCache::dispatch(true);

        return response()->json([
            'success' => true,
            'message' => 'Cache clearing job dispatched successfully',
        ]);
    }

    /**
     * Clear expired cache
     */
    public function clearExpired(): JsonResponse
    {
        ClearReportCache::dispatch(false);

        return response()->json([
            'success' => true,
            'message' => 'Expired cache clearing job dispatched successfully',
        ]);
    }

    /**
     * Clear cache for specific report type
     */
    public function clearByType(Request $request): JsonResponse
    {
        $request->validate([
            'report_type' => 'required|string',
        ]);

        $tenantId = $this->currentTenantId();

        if (! $tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'No tenant context available to clear cache.',
            ], 403);
        }

        ClearReportCache::clearByReportType($request->input('report_type'), $tenantId);

        return response()->json([
            'success' => true,
            'message' => 'Cache cleared for report type',
        ]);
    }

    /**
     * Warm cache for common reports
     */
    public function warmCache(): JsonResponse
    {
        // This would dispatch jobs to pre-generate common reports
        // For now, we'll just return a success message
        // In production, you would dispatch jobs for common report combinations

        return response()->json([
            'success' => true,
            'message' => 'Cache warming initiated',
        ]);
    }

    /**
     * Get cache entry details (tenant-scoped)
     */
    public function show(string $cacheKey): JsonResponse
    {
        $tenantId = $this->currentTenantId();
        $query = ReportCache::where('cache_key', $cacheKey);

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $entry = $query->first();

        if (! $entry) {
            return response()->json([
                'success' => false,
                'message' => 'Cache entry not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $entry,
        ]);
    }

    /**
     * Delete specific cache entry (tenant-scoped)
     */
    public function destroy(string $cacheKey): JsonResponse
    {
        $tenantId = $this->currentTenantId();
        $query = ReportCache::where('cache_key', $cacheKey);

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $deleted = $query->delete();

        if ($deleted === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cache entry not found',
            ], 404);
        }

        // Also clear from Redis
        Cache::forget($cacheKey);

        return response()->json([
            'success' => true,
            'message' => 'Cache entry deleted successfully',
        ]);
    }
}
