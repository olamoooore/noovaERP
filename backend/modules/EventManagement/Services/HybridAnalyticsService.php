<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Exception;

class HybridAnalyticsService
{
    private const REDIS_PREFIX = 'analytics:';
    private const CACHE_TTL = 3600; // 1 hour
    private const BATCH_SIZE = 1000;

    private $redis;

    public function __construct()
    {
        $this->redis = Redis::connection('default');
    }

    /**
     * Update real-time counter for immediate consistency
     */
    public function updateRealTimeCounter(string $metric, string $key, int $delta = 1, array $dimensions = []): void
    {
        try {
            $redisKey = $this->buildRedisKey($metric, $key, $dimensions);

            // Update counter with expiration
            $this->redis->pipeline(function ($pipe) use ($redisKey, $delta) {
                $pipe->incrby($redisKey, $delta);
                $pipe->expire($redisKey, 86400); // 24 hours
            });

            // Mark cache as dirty for batch processing
            $this->markCacheAsDirty($metric, $key, $dimensions);

        } catch (Exception $e) {
            Log::error('Failed to update real-time counter', [
                'metric' => $metric,
                'key' => $key,
                'delta' => $delta,
                'dimensions' => $dimensions,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get real-time counter value
     */
    public function getRealTimeCounter(string $metric, string $key, array $dimensions = []): int
    {
        try {
            $redisKey = $this->buildRedisKey($metric, $key, $dimensions);
            return (int) $this->redis->get($redisKey) ?: 0;

        } catch (Exception $e) {
            Log::error('Failed to get real-time counter', [
                'metric' => $metric,
                'key' => $key,
                'dimensions' => $dimensions,
                'error' => $e->getMessage()
            ]);

            return 0;
        }
    }

    /**
     * Process batch aggregation for complex analytics
     */
    public function processBatchAggregation(string $metric, Carbon $startTime, Carbon $endTime): array
    {
        try {
            switch ($metric) {
                case 'booking_revenue':
                    return $this->aggregateBookingRevenue($startTime, $endTime);

                case 'event_popularity':
                    return $this->aggregateEventPopularity($startTime, $endTime);

                case 'customer_lifetime_value':
                    return $this->aggregateCustomerLifetimeValue($startTime, $endTime);

                case 'artist_performance':
                    return $this->aggregateArtistPerformance($startTime, $endTime);

                case 'fraud_detection_metrics':
                    return $this->aggregateFraudDetectionMetrics($startTime, $endTime);

                default:
                    throw new Exception("Unknown metric: {$metric}");
            }

        } catch (Exception $e) {
            Log::error('Batch aggregation failed', [
                'metric' => $metric,
                'start_time' => $startTime->toISOString(),
                'end_time' => $endTime->toISOString(),
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Aggregate booking revenue with multiple dimensions
     */
    private function aggregateBookingRevenue(Carbon $startTime, Carbon $endTime): array
    {
        $results = DB::select("
            SELECT 
                DATE(b.created_at) as date,
                e.id as event_id,
                e.title as event_title,
                tt.id as ticket_type_id,
                tt.name as ticket_type_name,
                COUNT(DISTINCT b.id) as booking_count,
                SUM(bi.quantity) as tickets_sold,
                SUM(bi.quantity * bi.unit_price) as gross_revenue,
                SUM(bi.quantity * bi.unit_price * (1 - COALESCE(d.discount_percentage, 0) / 100)) as net_revenue,
                AVG(bi.unit_price) as avg_ticket_price
            FROM bookings b
            JOIN booking_items bi ON b.id = bi.booking_id
            JOIN ticket_types tt ON bi.ticket_type_id = tt.id
            JOIN events e ON tt.event_id = e.id
            LEFT JOIN discounts d ON b.discount_id = d.id
            WHERE b.status = 'confirmed'
            AND b.created_at BETWEEN ? AND ?
            GROUP BY DATE(b.created_at), e.id, e.title, tt.id, tt.name
            ORDER BY date DESC, gross_revenue DESC
        ", [$startTime, $endTime]);

        // Store aggregated results in cache
        $cacheKey = "batch_aggregation:booking_revenue:{$startTime->format('Y-m-d')}:{$endTime->format('Y-m-d')}";
        Cache::put($cacheKey, $results, self::CACHE_TTL);

        return $results;
    }

    /**
     * Aggregate event popularity metrics
     */
    private function aggregateEventPopularity(Carbon $startTime, Carbon $endTime): array
    {
        $results = DB::select("
            SELECT 
                e.id as event_id,
                e.title as event_title,
                e.max_capacity,
                COUNT(DISTINCT b.id) as total_bookings,
                SUM(bi.quantity) as tickets_sold,
                (SUM(bi.quantity) / e.max_capacity * 100) as capacity_utilization,
                COUNT(DISTINCT b.customer_id) as unique_customers,
                AVG(TIMESTAMPDIFF(MINUTE, b.created_at, b.updated_at)) as avg_booking_time_minutes,
                COUNT(DISTINCT CASE WHEN rs.risk_score > 70 THEN b.id END) as high_risk_bookings
            FROM events e
            LEFT JOIN ticket_types tt ON e.id = tt.event_id
            LEFT JOIN booking_items bi ON tt.id = bi.ticket_type_id
            LEFT JOIN bookings b ON bi.booking_id = b.id AND b.status = 'confirmed'
            LEFT JOIN risk_scores rs ON b.customer_id = rs.customer_id 
                AND rs.calculated_at BETWEEN b.created_at AND DATE_ADD(b.created_at, INTERVAL 1 HOUR)
            WHERE e.created_at BETWEEN ? AND ?
            GROUP BY e.id, e.title, e.max_capacity
            HAVING total_bookings > 0
            ORDER BY capacity_utilization DESC
        ", [$startTime, $endTime]);

        return $results;
    }

    /**
     * Aggregate customer lifetime value
     */
    private function aggregateCustomerLifetimeValue(Carbon $startTime, Carbon $endTime): array
    {
        $results = DB::select("
            SELECT 
                c.id as customer_id,
                c.email,
                ct.tier_name,
                COUNT(DISTINCT b.id) as total_bookings,
                SUM(bi.quantity * bi.unit_price) as total_spent,
                AVG(bi.quantity * bi.unit_price) as avg_order_value,
                MIN(b.created_at) as first_booking_date,
                MAX(b.created_at) as last_booking_date,
                DATEDIFF(MAX(b.created_at), MIN(b.created_at)) as customer_lifespan_days,
                COUNT(DISTINCT e.id) as unique_events_attended,
                SUM(ll.points_delta) as total_loyalty_points_earned
            FROM customers c
            JOIN bookings b ON c.id = b.customer_id
            JOIN booking_items bi ON b.id = bi.booking_id
            JOIN ticket_types tt ON bi.ticket_type_id = tt.id
            JOIN events e ON tt.event_id = e.id
            LEFT JOIN customer_tiers ct ON c.id = ct.customer_id
            LEFT JOIN loyalty_ledger ll ON c.id = ll.customer_id AND ll.transaction_type = 'earn'
            WHERE b.status = 'confirmed'
            AND b.created_at BETWEEN ? AND ?
            GROUP BY c.id, c.email, ct.tier_name
            ORDER BY total_spent DESC
        ", [$startTime, $endTime]);

        return $results;
    }

    /**
     * Aggregate artist performance metrics
     */
    private function aggregateArtistPerformance(Carbon $startTime, Carbon $endTime): array
    {
        $results = DB::select("
            SELECT 
                ac.canonical_name as artist_name,
                COUNT(DISTINCT e.id) as events_count,
                SUM(e.max_capacity) as total_capacity_offered,
                SUM(bi.quantity) as tickets_sold,
                SUM(bi.quantity * bi.unit_price) as gross_revenue,
                AVG(bi.unit_price) as avg_ticket_price,
                (SUM(bi.quantity) / SUM(e.max_capacity) * 100) as avg_capacity_utilization,
                COUNT(DISTINCT b.customer_id) as unique_fans,
                AVG(rs.risk_score) as avg_booking_risk_score
            FROM artist_canonical ac
            JOIN artists a ON ac.master_record_id = a.id
            JOIN artist_events ae ON a.id = ae.artist_id
            JOIN events e ON ae.event_id = e.id
            JOIN ticket_types tt ON e.id = tt.event_id
            JOIN booking_items bi ON tt.id = bi.ticket_type_id
            JOIN bookings b ON bi.booking_id = b.id
            LEFT JOIN risk_scores rs ON b.customer_id = rs.customer_id 
                AND rs.calculated_at BETWEEN b.created_at AND DATE_ADD(b.created_at, INTERVAL 1 HOUR)
            WHERE b.status = 'confirmed'
            AND e.event_date BETWEEN ? AND ?
            GROUP BY ac.id, ac.canonical_name
            ORDER BY gross_revenue DESC
        ", [$startTime, $endTime]);

        return $results;
    }

    /**
     * Aggregate fraud detection metrics
     */
    private function aggregateFraudDetectionMetrics(Carbon $startTime, Carbon $endTime): array
    {
        $results = DB::select("
            SELECT 
                DATE(b.created_at) as date,
                COUNT(DISTINCT b.id) as total_bookings,
                COUNT(DISTINCT CASE WHEN rs.risk_score > 70 THEN b.id END) as high_risk_bookings,
                COUNT(DISTINCT CASE WHEN ft.event_type = 'fraud_confirmed' THEN b.id END) as confirmed_fraud,
                COUNT(DISTINCT CASE WHEN ft.event_type = 'cleared' THEN b.id END) as false_positives,
                AVG(rs.risk_score) as avg_risk_score,
                COUNT(DISTINCT CASE WHEN rs.risk_score > 70 AND ft.event_type = 'fraud_confirmed' THEN b.id END) / 
                NULLIF(COUNT(DISTINCT CASE WHEN rs.risk_score > 70 THEN b.id END), 0) * 100 as precision_rate,
                COUNT(DISTINCT CASE WHEN rs.risk_score > 70 AND ft.event_type = 'fraud_confirmed' THEN b.id END) / 
                NULLIF(COUNT(DISTINCT CASE WHEN ft.event_type = 'fraud_confirmed' THEN b.id END), 0) * 100 as recall_rate
            FROM bookings b
            LEFT JOIN risk_scores rs ON b.customer_id = rs.customer_id 
                AND rs.calculated_at BETWEEN b.created_at AND DATE_ADD(b.created_at, INTERVAL 1 HOUR)
            LEFT JOIN fraud_timeline ft ON b.id = ft.booking_id
            WHERE b.created_at BETWEEN ? AND ?
            GROUP BY DATE(b.created_at)
            ORDER BY date DESC
        ", [$startTime, $endTime]);

        return $results;
    }

    /**
     * Backfill historical data for analytics recalculation
     */
    public function backfillHistoricalData(string $metric, Carbon $startDate, Carbon $endDate): void
    {
        try {
            $currentDate = $startDate->copy();

            while ($currentDate->lte($endDate)) {
                $dayStart = $currentDate->copy()->startOfDay();
                $dayEnd = $currentDate->copy()->endOfDay();

                Log::info("Backfilling {$metric} for {$currentDate->toDateString()}");

                // Process batch aggregation for the day
                $results = $this->processBatchAggregation($metric, $dayStart, $dayEnd);

                // Store results in analytics cache
                $this->storeAnalyticsResults($metric, $currentDate, $results);

                $currentDate->addDay();

                // Prevent memory issues with large backfills
                if ($currentDate->diffInDays($startDate) % 10 === 0) {
                    gc_collect_cycles();
                }
            }

            Log::info("Completed backfill for {$metric}", [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString()
            ]);

        } catch (Exception $e) {
            Log::error('Historical data backfill failed', [
                'metric' => $metric,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Invalidate analytics cache for specific metric and timeframe
     */
    public function invalidateAnalyticsCache(string $metric, Carbon $date): void
    {
        try {
            // Mark cache entries as dirty
            $cacheKey = $this->buildCacheKey($metric, $date);

            DB::table('analytics_cache')
                ->where('cache_key', 'LIKE', "{$cacheKey}%")
                ->update(['is_dirty' => true]);

            // Clear Redis counters for the date
            $redisPattern = $this->buildRedisKey($metric, $date->format('Y-m-d'), []);
            $keys = $this->redis->keys("{$redisPattern}*");

            if (!empty($keys)) {
                $this->redis->del($keys);
            }

        } catch (Exception $e) {
            Log::error('Failed to invalidate analytics cache', [
                'metric' => $metric,
                'date' => $date->toDateString(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get hybrid analytics result (real-time + batch)
     */
    public function getHybridAnalytics(string $metric, array $filters = []): array
    {
        try {
            // Get real-time data
            $realTimeData = $this->getRealTimeData($metric, $filters);

            // Get batch aggregated data
            $batchData = $this->getBatchData($metric, $filters);

            // Merge and reconcile data
            return $this->mergeAnalyticsData($realTimeData, $batchData);

        } catch (Exception $e) {
            Log::error('Failed to get hybrid analytics', [
                'metric' => $metric,
                'filters' => $filters,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Build Redis key for counters
     */
    private function buildRedisKey(string $metric, string $key, array $dimensions = []): string
    {
        $dimensionString = empty($dimensions) ? '' : ':' . implode(':', array_values($dimensions));
        return self::REDIS_PREFIX . "{$metric}:{$key}{$dimensionString}";
    }

    /**
     * Build cache key for batch results
     */
    private function buildCacheKey(string $metric, Carbon $date): string
    {
        return "batch_aggregation:{$metric}:{$date->format('Y-m-d')}";
    }

    /**
     * Mark cache as dirty for batch processing
     */
    private function markCacheAsDirty(string $metric, string $key, array $dimensions): void
    {
        $cacheKey = $this->buildCacheKey($metric, Carbon::now());

        DB::table('analytics_cache')->updateOrInsert(
            ['cache_key' => $cacheKey],
            [
                'kpi_name' => $metric,
                'cache_value' => json_encode(['key' => $key, 'dimensions' => $dimensions]),
                'calculated_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addHours(24),
                'is_dirty' => true
            ]
        );
    }

    /**
     * Store analytics results in cache
     */
    private function storeAnalyticsResults(string $metric, Carbon $date, array $results): void
    {
        $cacheKey = $this->buildCacheKey($metric, $date);

        DB::table('analytics_cache')->updateOrInsert(
            ['cache_key' => $cacheKey],
            [
                'kpi_name' => $metric,
                'cache_value' => json_encode($results),
                'calculated_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addHours(24),
                'is_dirty' => false
            ]
        );
    }

    /**
     * Get real-time data for metric
     */
    private function getRealTimeData(string $metric, array $filters): array
    {
        // Implementation depends on specific metric and filters
        // This is a simplified version
        $today = Carbon::now()->format('Y-m-d');
        $counter = $this->getRealTimeCounter($metric, $today, $filters);

        return [
            'metric' => $metric,
            'date' => $today,
            'value' => $counter,
            'type' => 'real_time'
        ];
    }

    /**
     * Get batch aggregated data for metric
     */
    private function getBatchData(string $metric, array $filters): array
    {
        $cacheKey = $this->buildCacheKey($metric, Carbon::now());

        $cached = DB::table('analytics_cache')
            ->where('cache_key', $cacheKey)
            ->where('is_dirty', false)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if ($cached) {
            return json_decode($cached->cache_value, true);
        }

        // If no cached data, return empty array
        return [];
    }

    /**
     * Merge real-time and batch analytics data
     */
    private function mergeAnalyticsData(array $realTimeData, array $batchData): array
    {
        // Simple merge strategy - in production this would be more sophisticated
        return [
            'real_time' => $realTimeData,
            'batch' => $batchData,
            'merged_at' => Carbon::now()->toISOString()
        ];
    }
}