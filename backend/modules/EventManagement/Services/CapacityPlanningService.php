<?php

namespace Modules\EventManagement\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CapacityPlanningService - Task 11.4
 *
 * Implements tenant resource usage analysis, capacity forecasting, optimization
 * recommendations, automated scaling triggers, and tenant onboarding resource
 * allocation tools for multi-tenant capacity planning.
 *
 * Requirements: 27.5
 */
class CapacityPlanningService
{
    private const CACHE_PREFIX = 'capacity_planning:';

    private const FORECAST_PERIODS = ['1w', '1m', '3m', '6m', '1y'];

    private const RESOURCE_TYPES = ['cpu', 'memory', 'storage', 'network', 'database'];

    private const DEFAULT_BUFFER_PERCENT = 20.0;

    private const MIN_CONFIDENCE_LEVEL = 70.0;

    /**
     * Analyze tenant resource usage patterns
     *
     * @param  string  $tenantId  Tenant identifier
     * @param  array  $options  Analysis options
     * @return array Usage analysis result
     */
    public function analyzeResourceUsage(string $tenantId, array $options = []): array
    {
        try {
            $analysisWindow = $options['analysis_window'] ?? '30d';
            $resourceTypes = $options['resource_types'] ?? self::RESOURCE_TYPES;

            $startTime = $this->calculateStartTime($analysisWindow);
            $usageData = $this->collectUsageData($tenantId, $startTime, $resourceTypes);

            $analysis = [];
            foreach ($resourceTypes as $resourceType) {
                $resourceUsage = $usageData->where('resource_type', $resourceType);

                if ($resourceUsage->isEmpty()) {
                    continue;
                }

                $analysis[$resourceType] = $this->analyzeResourcePattern($resourceUsage, $resourceType);
            }

            // Calculate overall tenant health score
            $healthScore = $this->calculateTenantHealthScore($analysis);

            // Generate recommendations
            $recommendations = $this->generateUsageRecommendations($analysis);

            return [
                'success' => true,
                'tenant_id' => $tenantId,
                'analysis_window' => $analysisWindow,
                'resource_analysis' => $analysis,
                'health_score' => $healthScore,
                'recommendations' => $recommendations,
                'analyzed_at' => now(),
            ];

        } catch (Exception $e) {
            Log::error('Failed to analyze resource usage', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to analyze resource usage',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate capacity forecasts for tenant
     *
     * @param  string  $tenantId  Tenant identifier
     * @param  array  $options  Forecasting options
     * @return array Forecasting result
     */
    public function generateCapacityForecasts(string $tenantId, array $options = []): array
    {
        try {
            $forecastPeriods = $options['periods'] ?? self::FORECAST_PERIODS;
            $resourceTypes = $options['resource_types'] ?? self::RESOURCE_TYPES;
            $modelVersion = $options['model_version'] ?? 'v1.0';

            $forecasts = [];

            foreach ($resourceTypes as $resourceType) {
                foreach ($forecastPeriods as $period) {
                    $forecast = $this->generateResourceForecast($tenantId, $resourceType, $period, $modelVersion);

                    if ($forecast['success']) {
                        $forecasts[] = $forecast['forecast'];

                        // Store forecast in database
                        $this->storeForecast($tenantId, $resourceType, $period, $forecast['forecast']);
                    }
                }
            }

            return [
                'success' => true,
                'tenant_id' => $tenantId,
                'forecasts' => $forecasts,
                'model_version' => $modelVersion,
                'generated_at' => now(),
            ];

        } catch (Exception $e) {
            Log::error('Failed to generate capacity forecasts', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to generate forecasts',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate optimization recommendations
     *
     * @param  string  $tenantId  Tenant ident
    /**
     * Calculate start time for time range
     */
    private function calculateStartTime(string $timeRange): Carbon
    {
        return match ($timeRange) {
            '15m' => now()->subMinutes(15),
            '1h' => now()->subHour(),
            '6h' => now()->subHours(6),
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            '30d' => now()->subMonth(),
            '90d' => now()->subMonths(3),
            '1y' => now()->subYear(),
            default => now()->subDay()
        };
    }

    /**
     * Collect usage data for analysis
     */
    private function collectUsageData(string $tenantId, Carbon $startTime, array $resourceTypes): \Illuminate\Support\Collection
    {
        return DB::table('tenant_resource_usage')
            ->where('tenant_id', $tenantId)
            ->where('measured_at', '>=', $startTime)
            ->whereIn('resource_type', $resourceTypes)
            ->orderBy('measured_at')
            ->get();
    }

    /**
     * Analyze resource pattern for specific resource type
     */
    private function analyzeResourcePattern(\Illuminate\Support\Collection $resourceUsage, string $resourceType): array
    {
        $values = $resourceUsage->pluck('usage_percentage');

        return [
            'resource_type' => $resourceType,
            'sample_count' => $values->count(),
            'avg_usage' => round($values->avg(), 2),
            'min_usage' => $values->min(),
            'max_usage' => $values->max(),
            'p50_usage' => $this->calculatePercentile($values, 50),
            'p95_usage' => $this->calculatePercentile($values, 95),
            'p99_usage' => $this->calculatePercentile($values, 99),
            'trend' => $this->calculateTrend($values),
            'volatility' => $this->calculateVolatility($values),
            'growth_rate' => $this->calculateGrowthRate($resourceUsage),
            'seasonal_patterns' => $this->detectSeasonalPatterns($resourceUsage),
        ];
    }

    /**
     * Calculate percentile for collection
     */
    private function calculatePercentile(\Illuminate\Support\Collection $values, int $percentile): float
    {
        $sorted = $values->sort()->values();
        $index = ($percentile / 100) * ($sorted->count() - 1);

        if ($index == floor($index)) {
            return $sorted[$index];
        }

        $lower = $sorted[floor($index)];
        $upper = $sorted[ceil($index)];

        return $lower + ($upper - $lower) * ($index - floor($index));
    }

    /**
     * Calculate trend direction
     */
    private function calculateTrend(\Illuminate\Support\Collection $values): string
    {
        if ($values->count() < 2) {
            return 'stable';
        }

        $first = $values->take(ceil($values->count() / 3))->avg();
        $last = $values->slice(-ceil($values->count() / 3))->avg();

        $change = (($last - $first) / $first) * 100;

        if ($change > 5) {
            return 'increasing';
        }
        if ($change < -5) {
            return 'decreasing';
        }

        return 'stable';
    }

    /**
     * Calculate volatility (coefficient of variation)
     */
    private function calculateVolatility(\Illuminate\Support\Collection $values): float
    {
        $mean = $values->avg();
        if ($mean == 0) {
            return 0;
        }

        $variance = $values->map(fn ($x) => pow($x - $mean, 2))->avg();
        $stdDev = sqrt($variance);

        return round(($stdDev / $mean) * 100, 2);
    }

    /**
     * Calculate growth rate
     */
    private function calculateGrowthRate(\Illuminate\Support\Collection $resourceUsage): float
    {
        if ($resourceUsage->count() < 2) {
            return 0;
        }

        $first = $resourceUsage->first();
        $last = $resourceUsage->last();

        $daysDiff = Carbon::parse($last->measured_at)->diffInDays(Carbon::parse($first->measured_at));
        if ($daysDiff == 0) {
            return 0;
        }

        $growthRate = (($last->usage_value - $first->usage_value) / $first->usage_value) * 100;

        // Annualize the growth rate
        return round($growthRate * (365 / $daysDiff), 2);
    }

    /**
     * Detect seasonal patterns
     */
    private function detectSeasonalPatterns(\Illuminate\Support\Collection $resourceUsage): array
    {
        // Group by hour of day
        $hourlyPattern = $resourceUsage->groupBy(function ($item) {
            return Carbon::parse($item->measured_at)->hour;
        })->map(function ($group) {
            return $group->avg('usage_percentage');
        });

        // Group by day of week
        $dailyPattern = $resourceUsage->groupBy(function ($item) {
            return Carbon::parse($item->measured_at)->dayOfWeek;
        })->map(function ($group) {
            return $group->avg('usage_percentage');
        });

        return [
            'hourly_pattern' => $hourlyPattern->toArray(),
            'daily_pattern' => $dailyPattern->toArray(),
            'peak_hour' => $hourlyPattern->keys()->first(),
            'peak_day' => $dailyPattern->keys()->first(),
        ];
    }

    /**
     * Calculate tenant health score
     */
    private function calculateTenantHealthScore(array $analysis): array
    {
        $scores = [];
        $totalWeight = 0;

        foreach ($analysis as $resourceType => $resourceAnalysis) {
            $weight = $this->getResourceWeight($resourceType);
            $resourceScore = $this->calculateResourceHealthScore($resourceAnalysis);

            $scores[$resourceType] = $resourceScore;
            $totalWeight += $weight * $resourceScore;
        }

        $overallScore = count($analysis) > 0 ? $totalWeight / count($analysis) : 100;

        return [
            'overall_score' => round($overallScore, 1),
            'resource_scores' => $scores,
            'health_status' => $this->getHealthStatus($overallScore),
            'calculated_at' => now(),
        ];
    }

    /**
     * Get resource weight for health calculation
     */
    private function getResourceWeight(string $resourceType): float
    {
        return match ($resourceType) {
            'cpu' => 1.2,
            'memory' => 1.1,
            'database' => 1.0,
            'storage' => 0.8,
            'network' => 0.7,
            default => 1.0
        };
    }

    /**
     * Calculate resource health score
     */
    private function calculateResourceHealthScore(array $resourceAnalysis): float
    {
        $score = 100;

        // Penalize high average usage
        if ($resourceAnalysis['avg_usage'] > 80) {
            $score -= 20;
        } elseif ($resourceAnalysis['avg_usage'] > 60) {
            $score -= 10;
        }

        // Penalize high peak usage
        if ($resourceAnalysis['p95_usage'] > 90) {
            $score -= 15;
        } elseif ($resourceAnalysis['p95_usage'] > 75) {
            $score -= 8;
        }

        // Penalize high volatility
        if ($resourceAnalysis['volatility'] > 50) {
            $score -= 15;
        } elseif ($resourceAnalysis['volatility'] > 30) {
            $score -= 8;
        }

        // Penalize rapid growth
        if ($resourceAnalysis['growth_rate'] > 100) {
            $score -= 20;
        } elseif ($resourceAnalysis['growth_rate'] > 50) {
            $score -= 10;
        }

        return max(0, $score);
    }

    /**
     * Get health status from score
     */
    private function getHealthStatus(float $score): string
    {
        if ($score >= 80) {
            return 'excellent';
        }
        if ($score >= 60) {
            return 'good';
        }
        if ($score >= 40) {
            return 'warning';
        }

        return 'critical';
    }
}
