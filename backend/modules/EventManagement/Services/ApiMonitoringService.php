<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;
use Exception;

/**
 * ApiMonitoringService - Task 12.4
 * 
 * Service for API usage pattern analysis, performance monitoring and optimization
 * recommendations, API consumer onboarding and documentation, and API rate limiting
 * and quota management.
 * 
 * Requirements: 28.5
 */
class ApiMonitoringService
{
    private const CACHE_PREFIX = 'api_monitoring:';
    private const RATE_LIMIT_PREFIX = 'rate_limit:';
    private const ANALYTICS_CACHE_TTL = 300; // 5 minutes
    private const RATE_LIMIT_WINDOW = 60; // 1 minute in seconds

    /**
     * Analyze API usage patterns
     * 
     * @param array $options Analysis options
     * @return array Usage pattern analysis
     */
    public function analyzeUsagePatterns(array $options = []): array
    {
        try {
            $startDate = $options['start_date'] ?? now()->subDays(30);
            $endDate = $options['end_date'] ?? now();
            $tenantId = $options['tenant_id'] ?? null;
            $consumerType = $options['consumer_type'] ?? null;

            // Usage volume patterns
            $volumePatterns = $this->analyzeVolumePatterns($startDate, $endDate, $tenantId, $consumerType);

            // Temporal patterns (hourly, daily, weekly)
            $temporalPatterns = $this->analyzeTemporalPatterns($startDate, $endDate, $tenantId, $consumerType);

            // Endpoint popularity and usage distribution
            $endpointPatterns = $this->analyzeEndpointPatterns($startDate, $endDate, $tenantId, $consumerType);

            // Consumer behavior patterns
            $consumerPatterns = $this->analyzeConsumerPatterns($startDate, $endDate, $tenantId, $consumerType);

            // Error and performance patterns
            $errorPatterns = $this->analyzeErrorPatterns($startDate, $endDate, $tenantId, $consumerType);

            // Generate insights and recommendations
            $insights = $this->generateUsageInsights($volumePatterns, $temporalPatterns, $endpointPatterns, $consumerPatterns, $errorPatterns);

            return [
                'success' => true,
                'analysis_period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'filters' => [
                    'tenant_id' => $tenantId,
                    'consumer_type' => $consumerType
                ],
                'patterns' => [
                    'volume' => $volumePatterns,
                    'temporal' => $temporalPatterns,
                    'endpoints' => $endpointPatterns,
                    'consumers' => $consumerPatterns,
                    'errors' => $errorPatterns
                ],
                'insights' => $insights,
                'analyzed_at' => now()
            ];

        } catch (Exception $e) {
            Log::error('Failed to analyze API usage patterns', [
                'options' => $options,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to analyze usage patterns',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Monitor API performance and generate optimization recommendations
     * 
     * @param array $options Monitoring options
     * @return array Performance monitoring result
     */
    public function monitorPerformance(array $options = []): array
    {
        try {
            $timeWindow = $options['time_window'] ?? '1h';
            $tenantId = $options['tenant_id'] ?? null;
            $includeRecommendations = $options['include_recommendations'] ?? true;

            $startTime = $this->calculateStartTime($timeWindow);

            // Current performance metrics
            $currentMetrics = $this->getCurrentPerformanceMetrics($startTime, $tenantId);

            // Performance trends
            $performanceTrends = $this->getPerformanceTrends($startTime, $tenantId);

            // Bottleneck analysis
            $bottlenecks = $this->identifyBottlenecks($startTime, $tenantId);

            // SLA compliance
            $slaCompliance = $this->checkSlaCompliance($startTime, $tenantId);

            $result = [
                'success' => true,
                'monitoring_window' => $timeWindow,
                'tenant_id' => $tenantId,
                'current_metrics' => $currentMetrics,
                'performance_trends' => $performanceTrends,
                'bottlenecks' => $bottlenecks,
                'sla_compliance' => $slaCompliance,
                'monitored_at' => now()
            ];

            if ($includeRecommendations) {
                $result['optimization_recommendations'] = $this->generateOptimizationRecommendations(
                    $currentMetrics,
                    $performanceTrends,
                    $bottlenecks,
                    $slaCompliance
                );
            }

            return $result;

        } catch (Exception $e) {
            Log::error('Failed to monitor API performance', [
                'options' => $options,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to monitor performance',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Onboard new API consumer
     * 
     * @param array $consumerData Consumer information
     * @return array Onboarding result
     */
    public function onboardConsumer(array $consumerData): array
    {
        try {
            DB::beginTransaction();

            // Validate consumer data
            $this->validateConsumerData($consumerData);

            $consumerId = $consumerData['consumer_id'] ?? 'consumer_' . uniqid();

            // Register consumer
            $consumerDbId = DB::table('api_consumers')->insertGetId([
                'consumer_id' => $consumerId,
                'consumer_name' => $consumerData['consumer_name'],
                'consumer_type' => $consumerData['consumer_type'],
                'tenant_id' => $consumerData['tenant_id'] ?? null,
                'contact_email' => $consumerData['contact_email'] ?? null,
                'description' => $consumerData['description'] ?? null,
                'allowed_endpoints' => json_encode($consumerData['allowed_endpoints'] ?? []),
                'api_keys' => json_encode($consumerData['api_keys'] ?? []),
                'is_active' => true,
                'registered_at' => now(),
                'created_by_staff_id' => $consumerData['created_by'] ?? null,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Set up default rate limits
            $this->setupDefaultRateLimits($consumerId, $consumerData['consumer_type']);

            // Generate API documentation for consumer
            $documentation = $this->generateConsumerDocumentation($consumerId, $consumerData);

            // Create welcome package
            $welcomePackage = $this->createWelcomePackage($consumerId, $consumerData, $documentation);

            DB::commit();

            Log::info('API consumer onboarded', [
                'consumer_id' => $consumerId,
                'consumer_name' => $consumerData['consumer_name'],
                'consumer_type' => $consumerData['consumer_type'],
                'tenant_id' => $consumerData['tenant_id'] ?? null
            ]);

            return [
                'success' => true,
                'consumer_id' => $consumerId,
                'consumer_db_id' => $consumerDbId,
                'documentation' => $documentation,
                'welcome_package' => $welcomePackage,
                'message' => 'Consumer onboarded successfully'
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to onboard API consumer', [
                'consumer_data' => $consumerData,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to onboard consumer',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Enforce API rate limiting
     * 
     * @param string $consumerId Consumer identifier
     * @param string $endpoint API endpoint
     * @param string $method HTTP method
     * @param array $context Request context
     * @return array Rate limiting result
     */
    public function enforceRateLimit(string $consumerId, string $endpoint, string $method, array $context = []): array
    {
        try {
            $consumerType = $context['consumer_type'] ?? 'unknown';

            // Get rate limit configuration
            $rateLimits = $this->getRateLimits($consumerId, $consumerType, $endpoint, $method);

            if (empty($rateLimits)) {
                return [
                    'allowed' => true,
                    'reason' => 'No rate limits configured'
                ];
            }

            $currentTime = now();
            $violations = [];

            foreach ($rateLimits as $limit) {
                $violation = $this->checkRateLimit($consumerId, $endpoint, $method, $limit, $currentTime);

                if ($violation) {
                    $violations[] = $violation;

                    // Record violation
                    $this->recordRateLimitViolation($consumerId, $endpoint, $method, $limit, $violation, $context);

                    // Apply enforcement action
                    if ($limit->enforcement_action === 'reject') {
                        return [
                            'allowed' => false,
                            'reason' => 'Rate limit exceeded',
                            'violation' => $violation,
                            'retry_after' => $this->calculateRetryAfter($limit)
                        ];
                    }
                }
            }

            // Increment usage counters
            $this->incrementUsageCounters($consumerId, $endpoint, $method, $currentTime);

            $result = [
                'allowed' => true,
                'violations' => $violations
            ];

            // Add throttling information if applicable
            if (!empty($violations)) {
                $throttleViolation = collect($violations)->first(function ($v) use ($rateLimits) {
                    $limit = collect($rateLimits)->first(function ($l) use ($v) {
                        return $l->id === $v['limit_id'];
                    });
                    return $limit && $limit->enforcement_action === 'throttle';
                });

                if ($throttleViolation) {
                    $result['throttled'] = true;
                    $result['throttle_delay'] = $this->calculateThrottleDelay($throttleViolation);
                }
            }

            return $result;

        } catch (Exception $e) {
            Log::error('Failed to enforce rate limit', [
                'consumer_id' => $consumerId,
                'endpoint' => $endpoint,
                'method' => $method,
                'error' => $e->getMessage()
            ]);

            return [
                'allowed' => true,
                'reason' => 'Rate limiting error - allowing request',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Manage API quotas
     * 
     * @param string $consumerId Consumer identifier
     * @param array $quotaData Quota configuration
     * @return array Quota management result
     */
    public function manageQuotas(string $consumerId, array $quotaData): array
    {
        try {
            DB::beginTransaction();

            $quotaConfig = [
                'monthly_requests' => $quotaData['monthly_requests'] ?? null,
                'yearly_requests' => $quotaData['yearly_requests'] ?? null,
                'data_transfer_gb' => $quotaData['data_transfer_gb'] ?? null,
                'concurrent_connections' => $quotaData['concurrent_connections'] ?? null,
                'custom_quotas' => $quotaData['custom_quotas'] ?? []
            ];

            // Update or create rate limit entry with quota configuration
            $existingLimit = DB::table('api_rate_limits')
                ->where('consumer_id', $consumerId)
                ->where('endpoint_pattern', '*')
                ->where('is_active', true)
                ->first();

            if ($existingLimit) {
                DB::table('api_rate_limits')
                    ->where('id', $existingLimit->id)
                    ->update([
                        'quota_config' => json_encode($quotaConfig),
                        'updated_at' => now()
                    ]);

                $limitId = $existingLimit->id;
            } else {
                $limitId = DB::table('api_rate_limits')->insertGetId([
                    'consumer_type' => 'consumer',
                    'consumer_id' => $consumerId,
                    'endpoint_pattern' => '*',
                    'method' => '*',
                    'quota_config' => json_encode($quotaConfig),
                    'is_active' => true,
                    'effective_from' => now(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            DB::commit();

            // Initialize quota tracking
            $this->initializeQuotaTracking($consumerId, $quotaConfig);

            Log::info('API quotas managed', [
                'consumer_id' => $consumerId,
                'limit_id' => $limitId,
                'quota_config' => $quotaConfig
            ]);

            return [
                'success' => true,
                'consumer_id' => $consumerId,
                'limit_id' => $limitId,
                'quota_config' => $quotaConfig,
                'message' => 'Quotas managed successfully'
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to manage API quotas', [
                'consumer_id' => $consumerId,
                'quota_data' => $quotaData,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to manage quotas',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get API consumer analytics
     * 
     * @param string $consumerId Consumer identifier
     * @param array $options Analytics options
     * @return array Consumer analytics
     */
    public function getConsumerAnalytics(string $consumerId, array $options = []): array
    {
        try {
            $startDate = $options['start_date'] ?? now()->subDays(30);
            $endDate = $options['end_date'] ?? now();

            // Usage statistics
            $usageStats = $this->getConsumerUsageStats($consumerId, $startDate, $endDate);

            // Performance metrics
            $performanceMetrics = $this->getConsumerPerformanceMetrics($consumerId, $startDate, $endDate);

            // Rate limit violations
            $rateLimitViolations = $this->getConsumerRateLimitViolations($consumerId, $startDate, $endDate);

            // Quota utilization
            $quotaUtilization = $this->getConsumerQuotaUtilization($consumerId);

            // Usage trends
            $usageTrends = $this->getConsumerUsageTrends($consumerId, $startDate, $endDate);

            return [
                'success' => true,
                'consumer_id' => $consumerId,
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'usage_statistics' => $usageStats,
                'performance_metrics' => $performanceMetrics,
                'rate_limit_violations' => $rateLimitViolations,
                'quota_utilization' => $quotaUtilization,
                'usage_trends' => $usageTrends,
                'generated_at' => now()
            ];

        } catch (Exception $e) {
            Log::error('Failed to get consumer analytics', [
                'consumer_id' => $consumerId,
                'options' => $options,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to get consumer analytics',
                'message' => $e->getMessage()
            ];
        }
    }
    /**
     * Calculate start time for time window
     */
    private function calculateStartTime(string $timeWindow): Carbon
    {
        return match ($timeWindow) {
            '15m' => now()->subMinutes(15),
            '1h' => now()->subHour(),
            '6h' => now()->subHours(6),
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            '30d' => now()->subMonth(),
            default => now()->subHour()
        };
    }

    /**
     * Analyze volume patterns
     */
    private function analyzeVolumePatterns(Carbon $startDate, Carbon $endDate, ?string $tenantId, ?string $consumerType): array
    {
        $query = DB::table('api_usage_analytics')
            ->whereBetween('usage_date', [$startDate, $endDate]);

        if ($tenantId)
            $query->where('tenant_id', $tenantId);
        if ($consumerType)
            $query->where('consumer_type', $consumerType);

        $volumeData = $query->selectRaw('
                usage_date,
                SUM(request_count) as daily_requests,
                AVG(avg_response_time_ms) as daily_avg_response_time,
                AVG(error_rate_percent) as daily_error_rate
            ')
            ->groupBy('usage_date')
            ->orderBy('usage_date')
            ->get();

        $totalRequests = $volumeData->sum('daily_requests');
        $avgDailyRequests = $volumeData->avg('daily_requests');
        $peakDay = $volumeData->sortByDesc('daily_requests')->first();

        return [
            'total_requests' => $totalRequests,
            'avg_daily_requests' => round($avgDailyRequests, 2),
            'peak_day' => $peakDay ? [
                'date' => $peakDay->usage_date,
                'requests' => $peakDay->daily_requests
            ] : null,
            'daily_volume' => $volumeData->toArray()
        ];
    }

    /**
     * Analyze temporal patterns
     */
    private function analyzeTemporalPatterns(Carbon $startDate, Carbon $endDate, ?string $tenantId, ?string $consumerType): array
    {
        // This would require more detailed timestamp data
        // For now, return basic patterns based on available data
        $query = DB::table('api_usage_analytics')
            ->whereBetween('usage_date', [$startDate, $endDate]);

        if ($tenantId)
            $query->where('tenant_id', $tenantId);
        if ($consumerType)
            $query->where('consumer_type', $consumerType);

        // Weekly patterns (day of week)
        $weeklyPattern = $query->selectRaw('
                DAYOFWEEK(usage_date) as day_of_week,
                AVG(request_count) as avg_requests
            ')
            ->groupBy('day_of_week')
            ->orderBy('day_of_week')
            ->get()
            ->pluck('avg_requests', 'day_of_week')
            ->toArray();

        return [
            'weekly_pattern' => $weeklyPattern,
            'peak_day_of_week' => array_search(max($weeklyPattern), $weeklyPattern),
            'lowest_day_of_week' => array_search(min($weeklyPattern), $weeklyPattern)
        ];
    }

    /**
     * Analyze endpoint patterns
     */
    private function analyzeEndpointPatterns(Carbon $startDate, Carbon $endDate, ?string $tenantId, ?string $consumerType): array
    {
        $query = DB::table('api_usage_analytics')
            ->whereBetween('usage_date', [$startDate, $endDate]);

        if ($tenantId)
            $query->where('tenant_id', $tenantId);
        if ($consumerType)
            $query->where('consumer_type', $consumerType);

        $endpointStats = $query->selectRaw('
                endpoint,
                method,
                SUM(request_count) as total_requests,
                AVG(avg_response_time_ms) as avg_response_time,
                AVG(error_rate_percent) as avg_error_rate
            ')
            ->groupBy(['endpoint', 'method'])
            ->orderBy('total_requests', 'desc')
            ->limit(20)
            ->get();

        return [
            'top_endpoints' => $endpointStats->toArray(),
            'total_unique_endpoints' => $endpointStats->count()
        ];
    }

    /**
     * Analyze consumer patterns
     */
    private function analyzeConsumerPatterns(Carbon $startDate, Carbon $endDate, ?string $tenantId, ?string $consumerType): array
    {
        $query = DB::table('api_usage_analytics')
            ->whereBetween('usage_date', [$startDate, $endDate]);

        if ($tenantId)
            $query->where('tenant_id', $tenantId);
        if ($consumerType)
            $query->where('consumer_type', $consumerType);

        $consumerStats = $query->selectRaw('
                consumer_type,
                consumer_id,
                SUM(request_count) as total_requests,
                AVG(avg_response_time_ms) as avg_response_time,
                AVG(error_rate_percent) as avg_error_rate,
                COUNT(DISTINCT usage_date) as active_days
            ')
            ->groupBy(['consumer_type', 'consumer_id'])
            ->orderBy('total_requests', 'desc')
            ->limit(10)
            ->get();

        return [
            'top_consumers' => $consumerStats->toArray(),
            'consumer_distribution' => $query->selectRaw('
                    consumer_type,
                    COUNT(DISTINCT consumer_id) as unique_consumers,
                    SUM(request_count) as total_requests
                ')
                ->groupBy('consumer_type')
                ->get()
                ->toArray()
        ];
    }

    /**
     * Analyze error patterns
     */
    private function analyzeErrorPatterns(Carbon $startDate, Carbon $endDate, ?string $tenantId, ?string $consumerType): array
    {
        $query = DB::table('api_usage_analytics')
            ->whereBetween('usage_date', [$startDate, $endDate])
            ->where('error_rate_percent', '>', 0);

        if ($tenantId)
            $query->where('tenant_id', $tenantId);
        if ($consumerType)
            $query->where('consumer_type', $consumerType);

        $errorStats = $query->selectRaw('
                endpoint,
                method,
                AVG(error_rate_percent) as avg_error_rate,
                SUM(error_count) as total_errors,
                SUM(request_count) as total_requests
            ')
            ->groupBy(['endpoint', 'method'])
            ->having('avg_error_rate', '>', 1)
            ->orderBy('avg_error_rate', 'desc')
            ->get();

        return [
            'endpoints_with_errors' => $errorStats->toArray(),
            'overall_error_rate' => $query->avg('error_rate_percent') ?? 0
        ];
    }

    /**
     * Generate usage insights
     */
    private function generateUsageInsights(array $volumePatterns, array $temporalPatterns, array $endpointPatterns, array $consumerPatterns, array $errorPatterns): array
    {
        $insights = [];

        // Volume insights
        if ($volumePatterns['peak_day']) {
            $insights[] = [
                'type' => 'volume',
                'message' => "Peak usage day was {$volumePatterns['peak_day']['date']} with {$volumePatterns['peak_day']['requests']} requests",
                'priority' => 'info'
            ];
        }

        // Error insights
        if ($errorPatterns['overall_error_rate'] > 5) {
            $insights[] = [
                'type' => 'error',
                'message' => "High overall error rate detected: {$errorPatterns['overall_error_rate']}%",
                'priority' => 'high'
            ];
        }

        // Endpoint insights
        if (!empty($endpointPatterns['top_endpoints'])) {
            $topEndpoint = $endpointPatterns['top_endpoints'][0];
            $insights[] = [
                'type' => 'endpoint',
                'message' => "Most used endpoint: {$topEndpoint['method']} {$topEndpoint['endpoint']} ({$topEndpoint['total_requests']} requests)",
                'priority' => 'info'
            ];
        }

        return $insights;
    }

    /**
     * Get current performance metrics
     */
    private function getCurrentPerformanceMetrics(Carbon $startTime, ?string $tenantId): object
    {
        $query = DB::table('api_usage_analytics')
            ->where('usage_date', '>=', $startTime->toDateString());

        if ($tenantId)
            $query->where('tenant_id', $tenantId);

        return $query->selectRaw('
                SUM(request_count) as total_requests,
                AVG(avg_response_time_ms) as avg_response_time,
                AVG(error_rate_percent) as avg_error_rate,
                COUNT(DISTINCT endpoint) as unique_endpoints
            ')
            ->first() ?? (object) [
                'total_requests' => 0,
                'avg_response_time' => 0,
                'avg_error_rate' => 0,
                'unique_endpoints' => 0
            ];
    }

    /**
     * Get performance trends
     */
    private function getPerformanceTrends(Carbon $startTime, ?string $tenantId): array
    {
        $query = DB::table('api_usage_analytics')
            ->where('usage_date', '>=', $startTime->toDateString());

        if ($tenantId)
            $query->where('tenant_id', $tenantId);

        return $query->selectRaw('
                usage_date,
                SUM(request_count) as daily_requests,
                AVG(avg_response_time_ms) as daily_avg_response_time,
                AVG(error_rate_percent) as daily_error_rate
            ')
            ->groupBy('usage_date')
            ->orderBy('usage_date')
            ->get()
            ->toArray();
    }

    /**
     * Identify bottlenecks
     */
    private function identifyBottlenecks(Carbon $startTime, ?string $tenantId): array
    {
        $query = DB::table('api_usage_analytics')
            ->where('usage_date', '>=', $startTime->toDateString());

        if ($tenantId)
            $query->where('tenant_id', $tenantId);

        $slowEndpoints = $query->selectRaw('
                endpoint,
                method,
                AVG(avg_response_time_ms) as avg_response_time,
                SUM(request_count) as total_requests
            ')
            ->groupBy(['endpoint', 'method'])
            ->having('avg_response_time', '>', 1000) // Slower than 1 second
            ->orderBy('avg_response_time', 'desc')
            ->get();

        return [
            'slow_endpoints' => $slowEndpoints->toArray(),
            'bottleneck_count' => $slowEndpoints->count()
        ];
    }

    /**
     * Check SLA compliance
     */
    private function checkSlaCompliance(Carbon $startTime, ?string $tenantId): array
    {
        // Define SLA thresholds
        $slaThresholds = [
            'response_time_ms' => 500,
            'error_rate_percent' => 1.0,
            'availability_percent' => 99.9
        ];

        $query = DB::table('api_usage_analytics')
            ->where('usage_date', '>=', $startTime->toDateString());

        if ($tenantId)
            $query->where('tenant_id', $tenantId);

        $metrics = $query->selectRaw('
                AVG(avg_response_time_ms) as avg_response_time,
                AVG(error_rate_percent) as avg_error_rate
            ')
            ->first();

        $responseTimeCompliant = ($metrics->avg_response_time ?? 0) <= $slaThresholds['response_time_ms'];
        $errorRateCompliant = ($metrics->avg_error_rate ?? 0) <= $slaThresholds['error_rate_percent'];

        return [
            'response_time' => [
                'compliant' => $responseTimeCompliant,
                'actual' => $metrics->avg_response_time ?? 0,
                'threshold' => $slaThresholds['response_time_ms']
            ],
            'error_rate' => [
                'compliant' => $errorRateCompliant,
                'actual' => $metrics->avg_error_rate ?? 0,
                'threshold' => $slaThresholds['error_rate_percent']
            ],
            'overall_compliant' => $responseTimeCompliant && $errorRateCompliant
        ];
    }

    /**
     * Generate optimization recommendations
     */
    private function generateOptimizationRecommendations(object $currentMetrics, array $performanceTrends, array $bottlenecks, array $slaCompliance): array
    {
        $recommendations = [];

        // Response time recommendations
        if (!$slaCompliance['response_time']['compliant']) {
            $recommendations[] = [
                'type' => 'performance',
                'priority' => 'high',
                'title' => 'Response Time Optimization',
                'description' => 'Average response time exceeds SLA threshold',
                'actions' => [
                    'Implement caching for frequently accessed endpoints',
                    'Optimize database queries',
                    'Consider API response compression'
                ]
            ];
        }

        // Error rate recommendations
        if (!$slaCompliance['error_rate']['compliant']) {
            $recommendations[] = [
                'type' => 'reliability',
                'priority' => 'high',
                'title' => 'Error Rate Reduction',
                'description' => 'Error rate exceeds acceptable threshold',
                'actions' => [
                    'Implement better input validation',
                    'Add circuit breaker patterns',
                    'Improve error handling and logging'
                ]
            ];
        }

        // Bottleneck recommendations
        if ($bottlenecks['bottleneck_count'] > 0) {
            $recommendations[] = [
                'type' => 'bottleneck',
                'priority' => 'medium',
                'title' => 'Bottleneck Resolution',
                'description' => "Found {$bottlenecks['bottleneck_count']} slow endpoints",
                'actions' => [
                    'Profile and optimize slow endpoints',
                    'Implement endpoint-specific caching',
                    'Consider asynchronous processing for heavy operations'
                ]
            ];
        }

        return $recommendations;
    }

    /**
     * Validate consumer data
     */
    private function validateConsumerData(array $consumerData): void
    {
        $required = ['consumer_name', 'consumer_type'];

        foreach ($required as $field) {
            if (!isset($consumerData[$field])) {
                throw new Exception("Required field {$field} is missing");
            }
        }

        $validTypes = ['internal', 'partner', 'public', 'mobile', 'web'];
        if (!in_array($consumerData['consumer_type'], $validTypes)) {
            throw new Exception('Invalid consumer type');
        }
    }

    /**
     * Setup default rate limits for consumer
     */
    private function setupDefaultRateLimits(string $consumerId, string $consumerType): void
    {
        $defaultLimits = match ($consumerType) {
            'internal' => [
                'requests_per_minute' => 1000,
                'requests_per_hour' => 50000,
                'requests_per_day' => 1000000
            ],
            'partner' => [
                'requests_per_minute' => 500,
                'requests_per_hour' => 25000,
                'requests_per_day' => 500000
            ],
            'public' => [
                'requests_per_minute' => 100,
                'requests_per_hour' => 5000,
                'requests_per_day' => 100000
            ],
            default => [
                'requests_per_minute' => 60,
                'requests_per_hour' => 3000,
                'requests_per_day' => 50000
            ]
        };

        DB::table('api_rate_limits')->insert([
            'consumer_type' => 'consumer',
            'consumer_id' => $consumerId,
            'endpoint_pattern' => '*',
            'method' => '*',
            'requests_per_minute' => $defaultLimits['requests_per_minute'],
            'requests_per_hour' => $defaultLimits['requests_per_hour'],
            'requests_per_day' => $defaultLimits['requests_per_day'],
            'burst_limit' => $defaultLimits['requests_per_minute'] * 2,
            'is_active' => true,
            'enforcement_action' => 'throttle',
            'effective_from' => now(),
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    /**
     * Generate consumer documentation
     */
    private function generateConsumerDocumentation(string $consumerId, array $consumerData): array
    {
        return [
            'consumer_id' => $consumerId,
            'getting_started_guide' => 'https://docs.example.com/getting-started',
            'api_reference' => 'https://docs.example.com/api-reference',
            'authentication_guide' => 'https://docs.example.com/authentication',
            'rate_limits' => 'https://docs.example.com/rate-limits',
            'support_contact' => 'api-support@example.com'
        ];
    }

    /**
     * Create welcome package
     */
    private function createWelcomePackage(string $consumerId, array $consumerData, array $documentation): array
    {
        return [
            'welcome_message' => "Welcome to the ERP Event Management API, {$consumerData['consumer_name']}!",
            'api_key' => 'key_' . uniqid(), // In real implementation, generate secure API key
            'documentation_links' => $documentation,
            'sample_requests' => [
                'GET /api/v1/events' => 'List all events',
                'POST /api/v1/bookings' => 'Create a new booking'
            ],
            'support_information' => [
                'email' => 'api-support@example.com',
                'documentation' => 'https://docs.example.com',
                'status_page' => 'https://status.example.com'
            ]
        ];
    }

    /**
     * Get rate limits for consumer
     */
    private function getRateLimits(string $consumerId, string $consumerType, string $endpoint, string $method): array
    {
        return DB::table('api_rate_limits')
            ->where('is_active', true)
            ->where(function ($query) use ($consumerId, $consumerType) {
                $query->where('consumer_id', $consumerId)
                    ->orWhere('consumer_type', $consumerType);
            })
            ->where(function ($query) use ($endpoint, $method) {
                $query->where('endpoint_pattern', '*')
                    ->orWhere('endpoint_pattern', $endpoint);
            })
            ->where(function ($query) use ($method) {
                $query->where('method', '*')
                    ->orWhere('method', $method);
            })
            ->get()
            ->toArray();
    }

    /**
     * Check rate limit
     */
    private function checkRateLimit(string $consumerId, string $endpoint, string $method, object $limit, Carbon $currentTime): ?array
    {
        $redisKey = self::RATE_LIMIT_PREFIX . "{$consumerId}:{$endpoint}:{$method}";

        // Check per-minute limit
        if ($limit->requests_per_minute) {
            $minuteKey = $redisKey . ':minute:' . $currentTime->format('Y-m-d-H-i');
            $minuteCount = Redis::get($minuteKey) ?? 0;

            if ($minuteCount >= $limit->requests_per_minute) {
                return [
                    'limit_id' => $limit->id,
                    'limit_type' => 'per_minute',
                    'limit_value' => $limit->requests_per_minute,
                    'current_usage' => $minuteCount,
                    'window_end' => $currentTime->copy()->addMinute()->startOfMinute()
                ];
            }
        }

        // Check per-hour limit
        if ($limit->requests_per_hour) {
            $hourKey = $redisKey . ':hour:' . $currentTime->format('Y-m-d-H');
            $hourCount = Redis::get($hourKey) ?? 0;

            if ($hourCount >= $limit->requests_per_hour) {
                return [
                    'limit_id' => $limit->id,
                    'limit_type' => 'per_hour',
                    'limit_value' => $limit->requests_per_hour,
                    'current_usage' => $hourCount,
                    'window_end' => $currentTime->copy()->addHour()->startOfHour()
                ];
            }
        }

        // Check per-day limit
        if ($limit->requests_per_day) {
            $dayKey = $redisKey . ':day:' . $currentTime->format('Y-m-d');
            $dayCount = Redis::get($dayKey) ?? 0;

            if ($dayCount >= $limit->requests_per_day) {
                return [
                    'limit_id' => $limit->id,
                    'limit_type' => 'per_day',
                    'limit_value' => $limit->requests_per_day,
                    'current_usage' => $dayCount,
                    'window_end' => $currentTime->copy()->addDay()->startOfDay()
                ];
            }
        }

        return null;
    }

    /**
     * Record rate limit violation
     */
    private function recordRateLimitViolation(string $consumerId, string $endpoint, string $method, object $limit, array $violation, array $context): void
    {
        DB::table('api_rate_limit_violations')->insert([
            'consumer_type' => 'consumer',
            'consumer_id' => $consumerId,
            'endpoint' => $endpoint,
            'method' => $method,
            'rate_limit_type' => $violation['limit_type'],
            'limit_value' => $violation['limit_value'],
            'current_usage' => $violation['current_usage'],
            'enforcement_action' => $limit->enforcement_action,
            'request_id' => $context['request_id'] ?? null,
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'occurred_at' => now(),
            'violation_context' => json_encode($context),
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    /**
     * Increment usage counters
     */
    private function incrementUsageCounters(string $consumerId, string $endpoint, string $method, Carbon $currentTime): void
    {
        $redisKey = self::RATE_LIMIT_PREFIX . "{$consumerId}:{$endpoint}:{$method}";

        // Increment minute counter
        $minuteKey = $redisKey . ':minute:' . $currentTime->format('Y-m-d-H-i');
        Redis::incr($minuteKey);
        Redis::expire($minuteKey, 120); // 2 minutes TTL

        // Increment hour counter
        $hourKey = $redisKey . ':hour:' . $currentTime->format('Y-m-d-H');
        Redis::incr($hourKey);
        Redis::expire($hourKey, 7200); // 2 hours TTL

        // Increment day counter
        $dayKey = $redisKey . ':day:' . $currentTime->format('Y-m-d');
        Redis::incr($dayKey);
        Redis::expire($dayKey, 172800); // 2 days TTL
    }

    /**
     * Calculate retry after seconds
     */
    private function calculateRetryAfter(object $limit): int
    {
        // Return seconds until next window
        return 60; // Simplified - return 1 minute
    }

    /**
     * Calculate throttle delay
     */
    private function calculateThrottleDelay(array $violation): int
    {
        // Calculate delay based on how much the limit was exceeded
        $excessPercent = (($violation['current_usage'] - $violation['limit_value']) / $violation['limit_value']) * 100;
        return min(30, max(1, intval($excessPercent / 10))); // 1-30 seconds delay
    }

    /**
     * Initialize quota tracking
     */
    private function initializeQuotaTracking(string $consumerId, array $quotaConfig): void
    {
        $currentMonth = now()->format('Y-m');
        $currentYear = now()->format('Y');

        // Initialize monthly quota tracking
        if (isset($quotaConfig['monthly_requests'])) {
            $monthlyKey = self::RATE_LIMIT_PREFIX . "quota:{$consumerId}:monthly:{$currentMonth}";
            Redis::setnx($monthlyKey, 0);
            Redis::expire($monthlyKey, now()->endOfMonth()->diffInSeconds());
        }

        // Initialize yearly quota tracking
        if (isset($quotaConfig['yearly_requests'])) {
            $yearlyKey = self::RATE_LIMIT_PREFIX . "quota:{$consumerId}:yearly:{$currentYear}";
            Redis::setnx($yearlyKey, 0);
            Redis::expire($yearlyKey, now()->endOfYear()->diffInSeconds());
        }
    }

    /**
     * Get consumer usage stats
     */
    private function getConsumerUsageStats(string $consumerId, Carbon $startDate, Carbon $endDate): object
    {
        return DB::table('api_usage_analytics')
            ->where('consumer_id', $consumerId)
            ->whereBetween('usage_date', [$startDate, $endDate])
            ->selectRaw('
                SUM(request_count) as total_requests,
                AVG(avg_response_time_ms) as avg_response_time,
                AVG(error_rate_percent) as avg_error_rate,
                COUNT(DISTINCT endpoint) as unique_endpoints_used,
                COUNT(DISTINCT usage_date) as active_days
            ')
            ->first() ?? (object) [
                'total_requests' => 0,
                'avg_response_time' => 0,
                'avg_error_rate' => 0,
                'unique_endpoints_used' => 0,
                'active_days' => 0
            ];
    }

    /**
     * Get consumer performance metrics
     */
    private function getConsumerPerformanceMetrics(string $consumerId, Carbon $startDate, Carbon $endDate): array
    {
        return DB::table('api_usage_analytics')
            ->where('consumer_id', $consumerId)
            ->whereBetween('usage_date', [$startDate, $endDate])
            ->selectRaw('
                endpoint,
                method,
                SUM(request_count) as total_requests,
                AVG(avg_response_time_ms) as avg_response_time,
                AVG(error_rate_percent) as avg_error_rate
            ')
            ->groupBy(['endpoint', 'method'])
            ->orderBy('total_requests', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Get consumer rate limit violations
     */
    private function getConsumerRateLimitViolations(string $consumerId, Carbon $startDate, Carbon $endDate): array
    {
        return DB::table('api_rate_limit_violations')
            ->where('consumer_id', $consumerId)
            ->whereBetween('occurred_at', [$startDate, $endDate])
            ->selectRaw('
                rate_limit_type,
                COUNT(*) as violation_count,
                MAX(occurred_at) as last_violation
            ')
            ->groupBy('rate_limit_type')
            ->get()
            ->toArray();
    }

    /**
     * Get consumer quota utilization
     */
    private function getConsumerQuotaUtilization(string $consumerId): array
    {
        $currentMonth = now()->format('Y-m');
        $currentYear = now()->format('Y');

        $monthlyKey = self::RATE_LIMIT_PREFIX . "quota:{$consumerId}:monthly:{$currentMonth}";
        $yearlyKey = self::RATE_LIMIT_PREFIX . "quota:{$consumerId}:yearly:{$currentYear}";

        $monthlyUsage = Redis::get($monthlyKey) ?? 0;
        $yearlyUsage = Redis::get($yearlyKey) ?? 0;

        return [
            'monthly_usage' => $monthlyUsage,
            'yearly_usage' => $yearlyUsage,
            'current_month' => $currentMonth,
            'current_year' => $currentYear
        ];
    }

    /**
     * Get consumer usage trends
     */
    private function getConsumerUsageTrends(string $consumerId, Carbon $startDate, Carbon $endDate): array
    {
        return DB::table('api_usage_analytics')
            ->where('consumer_id', $consumerId)
            ->whereBetween('usage_date', [$startDate, $endDate])
            ->selectRaw('
                usage_date,
                SUM(request_count) as daily_requests,
                AVG(avg_response_time_ms) as daily_avg_response_time,
                AVG(error_rate_percent) as daily_error_rate
            ')
            ->groupBy('usage_date')
            ->orderBy('usage_date')
            ->get()
            ->toArray();
    }
}