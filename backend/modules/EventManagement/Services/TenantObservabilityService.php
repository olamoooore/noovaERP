<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Carbon\Carbon;
use Exception;

/**
 * TenantObservabilityService - Task 11.2
 * 
 * Comprehensive tenant-aware metrics collection, API performance monitoring,
 * queue health tracking, and automated alerting for multi-tenant observability.
 * 
 * Requirements: 27.1, 27.2, 27.4
 */
class TenantObservabilityService
{
    private const CACHE_PREFIX = 'observability:';
    private const METRICS_BATCH_SIZE = 100;
    private const ALERT_COOLDOWN_SECONDS = 300; // 5 minutes
    private const DEFAULT_RETENTION_DAYS = 90;

    /**
     * Collect and store tenant-aware metrics
     * 
     * @param string $tenantId Tenant identifier
     * @param string $metricName Metric name
     * @param float $value Metric value
     * @param array $context Additional metric context
     * @return array Collection result
     */
    public function collectMetric(string $tenantId, string $metricName, float $value, array $context = []): array
    {
        try {
            // Validate metric data
            $this->validateMetricData($tenantId, $metricName, $value, $context);

            // Determine metric category and thresholds
            $metricCategory = $this->determineMetricCategory($metricName);
            $thresholds = $this->getMetricThresholds($tenantId, $metricName);

            // Calculate alert status
            $alertStatus = $this->calculateAlertStatus($value, $thresholds);

            // Store metric
            $metricId = DB::table('tenant_health_metrics')->insertGetId([
                'tenant_id' => $tenantId,
                'metric_name' => $metricName,
                'metric_category' => $metricCategory,
                'metric_value' => $value,
                'metric_unit' => $context['unit'] ?? null,
                'metric_tags' => json_encode($context['tags'] ?? []),
                'metric_metadata' => json_encode($context['metadata'] ?? []),
                'collected_at' => now(),
                'collection_source' => $context['source'] ?? 'api',
                'collection_method' => $context['method'] ?? 'push',
                'threshold_warning' => $thresholds['warning'] ?? null,
                'threshold_critical' => $thresholds['critical'] ?? null,
                'alert_status' => $alertStatus,
                'alert_triggered_at' => $alertStatus !== 'normal' ? now() : null,
                'alert_context' => json_encode($context['alert_context'] ?? []),
                'aggregation_period' => $context['aggregation_period'] ?? '1m',
                'is_aggregated' => $context['is_aggregated'] ?? false,
                'created_at' => now()
            ]);

            // Process alerts if needed
            if ($alertStatus !== 'normal') {
                $this->processMetricAlert($tenantId, $metricName, $value, $alertStatus, $context);
            }

            // Update cached metrics
            $this->updateMetricCache($tenantId, $metricName, $value, $context);

            return [
                'success' => true,
                'metric_id' => $metricId,
                'alert_status' => $alertStatus,
                'message' => 'Metric collected successfully'
            ];

        } catch (Exception $e) {
            Log::error('Failed to collect metric', [
                'tenant_id' => $tenantId,
                'metric_name' => $metricName,
                'value' => $value,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to collect metric',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Log API performance metrics
     * 
     * @param string $tenantId Tenant identifier
     * @param array $performanceData API performance data
     * @return array Logging result
     */
    public function logApiPerformance(string $tenantId, array $performanceData): array
    {
        try {
            // Validate performance data
            $this->validateApiPerformanceData($performanceData);

            // Extract performance breakdown
            $breakdown = $this->calculatePerformanceBreakdown($performanceData);

            // Store API performance log
            $logId = DB::table('api_performance_log')->insertGetId([
                'tenant_id' => $tenantId,
                'request_id' => $performanceData['request_id'],
                'endpoint' => $performanceData['endpoint'],
                'http_method' => $performanceData['method'],
                'route_name' => $performanceData['route_name'] ?? null,
                'response_status' => $performanceData['status'],
                'response_time_ms' => $performanceData['response_time'],
                'database_time_ms' => $performanceData['database_time'] ?? null,
                'cache_time_ms' => $performanceData['cache_time'] ?? null,
                'external_api_time_ms' => $performanceData['external_api_time'] ?? null,
                'memory_usage_mb' => $performanceData['memory_usage'] ?? null,
                'cpu_usage_percent' => $performanceData['cpu_usage'] ?? null,
                'request_size_bytes' => $performanceData['request_size'] ?? null,
                'response_size_bytes' => $performanceData['response_size'] ?? null,
                'user_agent' => $performanceData['user_agent'] ?? null,
                'ip_address' => $performanceData['ip_address'] ?? null,
                'user_id' => $performanceData['user_id'] ?? null,
                'request_headers' => json_encode($performanceData['request_headers'] ?? []),
                'response_headers' => json_encode($performanceData['response_headers'] ?? []),
                'query_parameters' => json_encode($performanceData['query_params'] ?? []),
                'request_body_sample' => json_encode($performanceData['body_sample'] ?? []),
                'error_details' => json_encode($performanceData['error_details'] ?? []),
                'performance_breakdown' => json_encode($breakdown),
                'trace_id' => $performanceData['trace_id'] ?? null,
                'span_id' => $performanceData['span_id'] ?? null,
                'request_started_at' => $performanceData['started_at'],
                'request_completed_at' => $performanceData['completed_at'] ?? now(),
                'created_at' => now()
            ]);

            // Update API performance metrics
            $this->updateApiMetrics($tenantId, $performanceData);

            // Check for performance alerts
            $this->checkApiPerformanceAlerts($tenantId, $performanceData);

            return [
                'success' => true,
                'log_id' => $logId,
                'message' => 'API performance logged successfully'
            ];

        } catch (Exception $e) {
            Log::error('Failed to log API performance', [
                'tenant_id' => $tenantId,
                'performance_data' => $performanceData,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to log API performance',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Monitor queue health and job performance
     * 
     * @param string $tenantId Tenant identifier
     * @param array $options Monitoring options
     * @return array Queue health data
     */
    public function monitorQueueHealth(string $tenantId, array $options = []): array
    {
        try {
            $queueNames = $options['queues'] ?? $this->getTenantQueues($tenantId);
            $healthData = [];

            foreach ($queueNames as $queueName) {
                $queueHealth = $this->collectQueueMetrics($tenantId, $queueName);
                $healthData[$queueName] = $queueHealth;

                // Store queue health metrics
                $this->storeQueueHealthMetrics($tenantId, $queueName, $queueHealth);

                // Check for queue alerts
                $this->checkQueueAlerts($tenantId, $queueName, $queueHealth);
            }

            return [
                'success' => true,
                'tenant_id' => $tenantId,
                'queue_health' => $healthData,
                'overall_health' => $this->calculateOverallQueueHealth($healthData),
                'measured_at' => now()
            ];

        } catch (Exception $e) {
            Log::error('Failed to monitor queue health', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to monitor queue health',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Create or update alert rules for tenant
     * 
     * @param string $tenantId Tenant identifier
     * @param array $ruleData Alert rule configuration
     * @return array Rule creation result
     */
    public function createAlertRule(string $tenantId, array $ruleData): array
    {
        try {
            DB::beginTransaction();

            // Validate rule data
            $this->validateAlertRuleData($ruleData);

            // Check if rule already exists
            $existingRule = DB::table('tenant_alert_rules')
                ->where('tenant_id', $tenantId)
                ->where('rule_name', $ruleData['rule_name'])
                ->first();

            if ($existingRule) {
                // Update existing rule
                DB::table('tenant_alert_rules')
                    ->where('id', $existingRule->id)
                    ->update([
                        'rule_type' => $ruleData['rule_type'],
                        'metric_name' => $ruleData['metric_name'],
                        'metric_category' => $ruleData['metric_category'],
                        'rule_conditions' => json_encode($ruleData['conditions']),
                        'rule_configuration' => json_encode($ruleData['configuration'] ?? []),
                        'severity_level' => $ruleData['severity_level'],
                        'is_enabled' => $ruleData['is_enabled'] ?? true,
                        'evaluation_interval_seconds' => $ruleData['evaluation_interval'] ?? 60,
                        'alert_cooldown_seconds' => $ruleData['cooldown_seconds'] ?? self::ALERT_COOLDOWN_SECONDS,
                        'notification_channels' => json_encode($ruleData['notification_channels'] ?? []),
                        'notification_templates' => json_encode($ruleData['notification_templates'] ?? []),
                        'updated_at' => now()
                    ]);

                $ruleId = $existingRule->id;
            } else {
                // Create new rule
                $ruleId = DB::table('tenant_alert_rules')->insertGetId([
                    'tenant_id' => $tenantId,
                    'rule_name' => $ruleData['rule_name'],
                    'rule_type' => $ruleData['rule_type'],
                    'metric_name' => $ruleData['metric_name'],
                    'metric_category' => $ruleData['metric_category'],
                    'rule_conditions' => json_encode($ruleData['conditions']),
                    'rule_configuration' => json_encode($ruleData['configuration'] ?? []),
                    'severity_level' => $ruleData['severity_level'],
                    'is_enabled' => $ruleData['is_enabled'] ?? true,
                    'evaluation_interval_seconds' => $ruleData['evaluation_interval'] ?? 60,
                    'alert_cooldown_seconds' => $ruleData['cooldown_seconds'] ?? self::ALERT_COOLDOWN_SECONDS,
                    'notification_channels' => json_encode($ruleData['notification_channels'] ?? []),
                    'notification_templates' => json_encode($ruleData['notification_templates'] ?? []),
                    'trigger_count' => 0,
                    'is_currently_alerting' => false,
                    'created_by_staff_id' => $ruleData['created_by'] ?? null,
                    'created_at' => now()
                ]);
            }

            DB::commit();

            Log::info('Alert rule created/updated', [
                'tenant_id' => $tenantId,
                'rule_id' => $ruleId,
                'rule_name' => $ruleData['rule_name']
            ]);

            return [
                'success' => true,
                'rule_id' => $ruleId,
                'message' => 'Alert rule created/updated successfully'
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create alert rule', [
                'tenant_id' => $tenantId,
                'rule_data' => $ruleData,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to create alert rule',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get tenant observability dashboard data
     * 
     * @param string $tenantId Tenant identifier
     * @param array $options Dashboard options
     * @return array Dashboard data
     */
    public function getTenantDashboard(string $tenantId, array $options = []): array
    {
        try {
            $timeRange = $options['time_range'] ?? '1h';
            $startTime = $this->calculateStartTime($timeRange);

            // Get key metrics
            $keyMetrics = $this->getKeyMetrics($tenantId, $startTime);

            // Get API performance summary
            $apiPerformance = $this->getApiPerformanceSummary($tenantId, $startTime);

            // Get queue health summary
            $queueHealth = $this->getQueueHealthSummary($tenantId, $startTime);

            // Get active alerts
            $activeAlerts = $this->getActiveAlerts($tenantId);

            // Get resource usage
            $resourceUsage = $this->getResourceUsage($tenantId, $startTime);

            // Get SLA compliance
            $slaCompliance = $this->getSlaCompliance($tenantId, $timeRange);

            return [
                'success' => true,
                'tenant_id' => $tenantId,
                'time_range' => $timeRange,
                'dashboard' => [
                    'key_metrics' => $keyMetrics,
                    'api_performance' => $apiPerformance,
                    'queue_health' => $queueHealth,
                    'active_alerts' => $activeAlerts,
                    'resource_usage' => $resourceUsage,
                    'sla_compliance' => $slaCompliance,
                    'last_updated' => now()
                ]
            ];

        } catch (Exception $e) {
            Log::error('Failed to get tenant dashboard', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to load dashboard',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Validate metric data
     */
    private function validateMetricData(string $tenantId, string $metricName, float $value, array $context): void
    {
        if (empty($tenantId)) {
            throw new Exception('Tenant ID is required');
        }

        if (empty($metricName)) {
            throw new Exception('Metric name is required');
        }

        if (!is_numeric($value)) {
            throw new Exception('Metric value must be numeric');
        }
    }

    /**
     * Determine metric category based on metric name
     */
    private function determineMetricCategory(string $metricName): string
    {
        $categoryMap = [
            'response_time' => 'performance',
            'error_rate' => 'errors',
            'throughput' => 'performance',
            'availability' => 'availability',
            'cpu_usage' => 'capacity',
            'memory_usage' => 'capacity',
            'disk_usage' => 'capacity',
            'queue_lag' => 'performance',
            'job_failure_rate' => 'errors'
        ];

        foreach ($categoryMap as $pattern => $category) {
            if (str_contains($metricName, $pattern)) {
                return $category;
            }
        }

        return 'custom';
    }

    /**
     * Get metric thresholds for tenant
     */
    private function getMetricThresholds(string $tenantId, string $metricName): array
    {
        $cacheKey = self::CACHE_PREFIX . "thresholds:{$tenantId}:{$metricName}";
        
        return Cache::remember($cacheKey, 300, function () use ($tenantId, $metricName) {
            $rule = DB::table('tenant_alert_rules')
                ->where('tenant_id', $tenantId)
                ->where('metric_name', $metricName)
                ->where('is_enabled', true)
                ->first();

            if (!$rule) {
                return $this->getDefaultThresholds($metricName);
            }

            $conditions = json_decode($rule->rule_conditions, true);
            return [
                'warning' => $conditions['warning_threshold'] ?? null,
                'critical' => $conditions['critical_threshold'] ?? null
            ];
        });
    }

    /**
     * Get default thresholds for metric
     */
    private function getDefaultThresholds(string $metricName): array
    {
        $defaults = [
            'response_time_ms' => ['warning' => 1000, 'critical' => 5000],
            'error_rate_percent' => ['warning' => 5, 'critical' => 10],
            'cpu_usage_percent' => ['warning' => 80, 'critical' => 95],
            'memory_usage_percent' => ['warning' => 85, 'critical' => 95],
            'queue_lag_seconds' => ['warning' => 60, 'critical' => 300],
            'availability_percent' => ['warning' => 99, 'critical' => 95]
        ];

        return $defaults[$metricName] ?? ['warning' => null, 'critical' => null];
    }

    /**
     * Calculate alert status based on thresholds
     */
    private function calculateAlertStatus(float $value, array $thresholds): string
    {
        if ($thresholds['critical'] !== null && $value >= $thresholds['critical']) {
            return 'critical';
        }

        if ($thresholds['warning'] !== null && $value >= $thresholds['warning']) {
            return 'warning';
        }

        return 'normal';
    }

    /**
     * Process metric alert
     */
    private function processMetricAlert(string $tenantId, string $metricName, float $value, string $alertStatus, array $context): void
    {
        $rule = DB::table('tenant_alert_rules')
            ->where('tenant_id', $tenantId)
            ->where('metric_name', $metricName)
            ->where('is_enabled', true)
            ->first();

        if (!$rule) {
            return;
        }

        // Check cooldown period
        if ($rule->last_triggered_at && 
            Carbon::parse($rule->last_triggered_at)->addSeconds($rule->alert_cooldown_seconds)->isFuture()) {
            return;
        }

        // Create incident
        $incidentId = 'INC-' . strtoupper(uniqid());
        
        DB::table('alert_incidents')->insert([
            'incident_id' => $incidentId,
            'tenant_id' => $tenantId,
            'alert_rule_id' => $rule->id,
            'alert_rule_name' => $rule->rule_name,
            'severity_level' => $alertStatus,
            'incident_status' => 'triggered',
            'triggered_at' => now(),
            'incident_description' => "Metric {$metricName} exceeded {$alertStatus} threshold with value {$value}",
            'trigger_data' => json_encode([
                'metric_name' => $metricName,
                'metric_value' => $value,
                'threshold_type' => $alertStatus,
                'context' => $context
            ]),
            'escalation_level' => 0,
            'created_at' => now()
        ]);

        // Update rule status
        DB::table('tenant_alert_rules')
            ->where('id', $rule->id)
            ->update([
                'last_triggered_at' => now(),
                'trigger_count' => $rule->trigger_count + 1,
                'is_currently_alerting' => true
            ]);

        Log::warning('Alert triggered', [
            'incident_id' => $incidentId,
            'tenant_id' => $tenantId,
            'metric_name' => $metricName,
            'value' => $value,
            'alert_status' => $alertStatus
        ]);
    }

    /**
     * Update metric cache
     */
    private function updateMetricCache(string $tenantId, string $metricName, float $value, array $context): void
    {
        $cacheKey = self::CACHE_PREFIX . "metric:{$tenantId}:{$metricName}";
        
        Cache::put($cacheKey, [
            'value' => $value,
            'timestamp' => now(),
            'context' => $context
        ], 300);
    }

    /**
     * Validate API performance data
     */
    private function validateApiPerformanceData(array $data): void
    {
        $required = ['request_id', 'endpoint', 'method', 'status', 'response_time', 'started_at'];
        
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new Exception("Required field {$field} is missing");
            }
        }
    }

    /**
     * Calculate performance breakdown
     */
    private function calculatePerformanceBreakdown(array $data): array
    {
        $totalTime = $data['response_time'];
        $dbTime = $data['database_time'] ?? 0;
        $cacheTime = $data['cache_time'] ?? 0;
        $externalTime = $data['external_api_time'] ?? 0;
        $applicationTime = $totalTime - $dbTime - $cacheTime - $externalTime;

        return [
            'total_time_ms' => $totalTime,
            'application_time_ms' => max(0, $applicationTime),
            'database_time_ms' => $dbTime,
            'cache_time_ms' => $cacheTime,
            'external_api_time_ms' => $externalTime,
            'breakdown_percentages' => [
                'application' => $totalTime > 0 ? round(($applicationTime / $totalTime) * 100, 2) : 0,
                'database' => $totalTime > 0 ? round(($dbTime / $totalTime) * 100, 2) : 0,
                'cache' => $totalTime > 0 ? round(($cacheTime / $totalTime) * 100, 2) : 0,
                'external_api' => $totalTime > 0 ? round(($externalTime / $totalTime) * 100, 2) : 0
            ]
        ];
    }

    /**
     * Update API metrics
     */
    private function updateApiMetrics(string $tenantId, array $performanceData): void
    {
        $endpoint = $performanceData['endpoint'];
        $responseTime = $performanceData['response_time'];
        $status = $performanceData['status'];

        // Update average response time
        $this->collectMetric($tenantId, 'api_response_time_ms', $responseTime, [
            'tags' => ['endpoint' => $endpoint],
            'source' => 'api_monitor'
        ]);

        // Update error rate if error status
        if ($status >= 400) {
            $this->collectMetric($tenantId, 'api_error_rate', 1, [
                'tags' => ['endpoint' => $endpoint, 'status' => $status],
                'source' => 'api_monitor'
            ]);
        }
    }

    /**
     * Check API performance alerts
     */
    private function checkApiPerformanceAlerts(string $tenantId, array $performanceData): void
    {
        $responseTime = $performanceData['response_time'];
        $status = $performanceData['status'];

        // Check response time thresholds
        $this->collectMetric($tenantId, 'api_response_time_ms', $responseTime, [
            'tags' => ['endpoint' => $performanceData['endpoint']],
            'source' => 'api_monitor'
        ]);

        // Check error rate for 5xx errors
        if ($status >= 500) {
            $this->collectMetric($tenantId, 'api_server_error_rate', 1, [
                'tags' => ['endpoint' => $performanceData['endpoint']],
                'source' => 'api_monitor'
            ]);
        }
    }

    /**
     * Get tenant queues
     */
    private function getTenantQueues(string $tenantId): array
    {
        return [
            "tenant_{$tenantId}_default",
            "tenant_{$tenantId}_notifications",
            "tenant_{$tenantId}_analytics",
            "tenant_{$tenantId}_exports"
        ];
    }

    /**
     * Collect queue metrics
     */
    private function collectQueueMetrics(string $tenantId, string $queueName): array
    {
        try {
            // Get queue size and job counts
            $pendingJobs = Queue::size($queueName);
            
            // Get recent job performance from database
            $recentJobs = DB::table('jobs')
                ->where('queue', $queueName)
                ->where('created_at', '>=', now()->subHour())
                ->get();

            $failedJobs = DB::table('failed_jobs')
                ->where('queue', $queueName)
                ->where('failed_at', '>=', now()->subHour())
                ->count();

            // Calculate metrics
            $totalJobs = $recentJobs->count();
            $avgProcessingTime = $totalJobs > 0 ? $recentJobs->avg('attempts') * 1000 : 0; // Approximate
            $errorRate = $totalJobs > 0 ? ($failedJobs / ($totalJobs + $failedJobs)) * 100 : 0;
            
            // Calculate queue lag
            $oldestJob = $recentJobs->sortBy('created_at')->first();
            $queueLag = $oldestJob ? now()->diffInSeconds($oldestJob->created_at) : 0;

            return [
                'pending_jobs' => $pendingJobs,
                'processing_jobs' => 0, // Would need Redis/queue driver specific implementation
                'failed_jobs' => $failedJobs,
                'completed_jobs' => $totalJobs,
                'avg_processing_time_ms' => $avgProcessingTime,
                'queue_lag_seconds' => $queueLag,
                'error_rate_percent' => $errorRate,
                'throughput_per_minute' => $totalJobs > 0 ? $totalJobs / 60 : 0
            ];

        } catch (Exception $e) {
            Log::error('Failed to collect queue metrics', [
                'queue_name' => $queueName,
                'error' => $e->getMessage()
            ]);

            return [
                'pending_jobs' => 0,
                'processing_jobs' => 0,
                'failed_jobs' => 0,
                'completed_jobs' => 0,
                'avg_processing_time_ms' => 0,
                'queue_lag_seconds' => 0,
                'error_rate_percent' => 0,
                'throughput_per_minute' => 0
            ];
        }
    }

    /**
     * Store queue health metrics
     */
    private function storeQueueHealthMetrics(string $tenantId, string $queueName, array $healthData): void
    {
        DB::table('queue_health_metrics')->insert([
            'tenant_id' => $tenantId,
            'queue_name' => $queueName,
            'pending_jobs' => $healthData['pending_jobs'],
            'processing_jobs' => $healthData['processing_jobs'],
            'failed_jobs' => $healthData['failed_jobs'],
            'completed_jobs' => $healthData['completed_jobs'],
            'avg_processing_time_ms' => $healthData['avg_processing_time_ms'],
            'queue_lag_seconds' => $healthData['queue_lag_seconds'],
            'error_rate_percent' => $healthData['error_rate_percent'],
            'throughput_per_minute' => $healthData['throughput_per_minute'],
            'measured_at' => now(),
            'created_at' => now()
        ]);
    }

    /**
     * Check queue alerts
     */
    private function checkQueueAlerts(string $tenantId, string $queueName, array $healthData): void
    {
        // Check queue lag
        if ($healthData['queue_lag_seconds'] > 0) {
            $this->collectMetric($tenantId, 'queue_lag_seconds', $healthData['queue_lag_seconds'], [
                'tags' => ['queue' => $queueName],
                'source' => 'queue_monitor'
            ]);
        }

        // Check error rate
        if ($healthData['error_rate_percent'] > 0) {
            $this->collectMetric($tenantId, 'queue_error_rate_percent', $healthData['error_rate_percent'], [
                'tags' => ['queue' => $queueName],
                'source' => 'queue_monitor'
            ]);
        }

        // Check pending jobs backlog
        if ($healthData['pending_jobs'] > 100) {
            $this->collectMetric($tenantId, 'queue_backlog_count', $healthData['pending_jobs'], [
                'tags' => ['queue' => $queueName],
                'source' => 'queue_monitor'
            ]);
        }
    }

    /**
     * Calculate overall queue health
     */
    private function calculateOverallQueueHealth(array $healthData): array
    {
        $totalPending = 0;
        $totalFailed = 0;
        $totalCompleted = 0;
        $maxLag = 0;
        $avgErrorRate = 0;

        foreach ($healthData as $queueHealth) {
            $totalPending += $queueHealth['pending_jobs'];
            $totalFailed += $queueHealth['failed_jobs'];
            $totalCompleted += $queueHealth['completed_jobs'];
            $maxLag = max($maxLag, $queueHealth['queue_lag_seconds']);
            $avgErrorRate += $queueHealth['error_rate_percent'];
        }

        $queueCount = count($healthData);
        $avgErrorRate = $queueCount > 0 ? $avgErrorRate / $queueCount : 0;

        // Calculate health score (0-100)
        $healthScore = 100;
        if ($maxLag > 300) $healthScore -= 30; // High lag penalty
        if ($avgErrorRate > 5) $healthScore -= 20; // High error rate penalty
        if ($totalPending > 1000) $healthScore -= 25; // High backlog penalty
        if ($totalFailed > 50) $healthScore -= 25; // High failure count penalty

        return [
            'health_score' => max(0, $healthScore),
            'total_pending' => $totalPending,
            'total_failed' => $totalFailed,
            'total_completed' => $totalCompleted,
            'max_queue_lag' => $maxLag,
            'avg_error_rate' => $avgErrorRate,
            'status' => $healthScore >= 80 ? 'healthy' : ($healthScore >= 60 ? 'warning' : 'critical')
        ];
    }

    /**
     * Validate alert rule data
     */
    private function validateAlertRuleData(array $ruleData): void
    {
        $required = ['rule_name', 'rule_type', 'metric_name', 'metric_category', 'conditions', 'severity_level'];
        
        foreach ($required as $field) {
            if (!isset($ruleData[$field])) {
                throw new Exception("Required field {$field} is missing");
            }
        }

        $validSeverities = ['info', 'warning', 'critical'];
        if (!in_array($ruleData['severity_level'], $validSeverities)) {
            throw new Exception('Invalid severity level');
        }

        $validRuleTypes = ['threshold', 'anomaly', 'trend', 'composite'];
        if (!in_array($ruleData['rule_type'], $validRuleTypes)) {
            throw new Exception('Invalid rule type');
        }
    }

    /**
     * Calculate start time for time range
     */
    private function calculateStartTime(string $timeRange): Carbon
    {
        return match($timeRange) {
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
     * Get key metrics for tenant
     */
    private function getKeyMetrics(string $tenantId, Carbon $startTime): array
    {
        $metrics = DB::table('tenant_health_metrics')
            ->where('tenant_id', $tenantId)
            ->where('collected_at', '>=', $startTime)
            ->whereIn('metric_name', [
                'api_response_time_ms',
                'api_error_rate_percent',
                'queue_lag_seconds',
                'cpu_usage_percent',
                'memory_usage_percent'
            ])
            ->selectRaw('
                metric_name,
                AVG(metric_value) as avg_value,
                MAX(metric_value) as max_value,
                MIN(metric_value) as min_value,
                COUNT(*) as sample_count
            ')
            ->groupBy('metric_name')
            ->get()
            ->keyBy('metric_name');

        return $metrics->toArray();
    }

    /**
     * Get API performance summary
     */
    private function getApiPerformanceSummary(string $tenantId, Carbon $startTime): array
    {
        return DB::table('api_performance_log')
            ->where('tenant_id', $tenantId)
            ->where('request_started_at', '>=', $startTime)
            ->selectRaw('
                COUNT(*) as total_requests,
                AVG(response_time_ms) as avg_response_time,
                MAX(response_time_ms) as max_response_time,
                COUNT(CASE WHEN response_status >= 400 THEN 1 END) as error_count,
                COUNT(CASE WHEN response_status >= 500 THEN 1 END) as server_error_count,
                AVG(CASE WHEN response_status < 400 THEN response_time_ms END) as avg_success_response_time
            ')
            ->first();
    }

    /**
     * Get queue health summary
     */
    private function getQueueHealthSummary(string $tenantId, Carbon $startTime): array
    {
        return DB::table('queue_health_metrics')
            ->where('tenant_id', $tenantId)
            ->where('measured_at', '>=', $startTime)
            ->selectRaw('
                SUM(pending_jobs) as total_pending,
                SUM(failed_jobs) as total_failed,
                SUM(completed_jobs) as total_completed,
                AVG(queue_lag_seconds) as avg_queue_lag,
                AVG(error_rate_percent) as avg_error_rate,
                MAX(queue_lag_seconds) as max_queue_lag
            ')
            ->first();
    }

    /**
     * Get active alerts for tenant
     */
    private function getActiveAlerts(string $tenantId): array
    {
        return DB::table('alert_incidents')
            ->where('tenant_id', $tenantId)
            ->whereIn('incident_status', ['triggered', 'acknowledged', 'investigating'])
            ->orderBy('triggered_at', 'desc')
            ->limit(10)
            ->get()
            ->toArray();
    }

    /**
     * Get resource usage for tenant
     */
    private function getResourceUsage(string $tenantId, Carbon $startTime): array
    {
        return DB::table('tenant_resource_usage')
            ->where('tenant_id', $tenantId)
            ->where('measured_at', '>=', $startTime)
            ->selectRaw('
                resource_type,
                AVG(usage_value) as avg_usage,
                MAX(usage_value) as max_usage,
                AVG(usage_percentage) as avg_usage_percent
            ')
            ->groupBy('resource_type')
            ->get()
            ->keyBy('resource_type')
            ->toArray();
    }

    /**
     * Get SLA compliance for tenant
     */
    private function getSlaCompliance(string $tenantId, string $timeRange): array
    {
        $period = match($timeRange) {
            '24h' => 'daily',
            '7d' => 'weekly',
            '30d' => 'monthly',
            default => 'daily'
        };

        return DB::table('tenant_sla_metrics')
            ->where('tenant_id', $tenantId)
            ->where('measurement_period', $period)
            ->where('period_start', '>=', $this->calculateStartTime($timeRange))
            ->selectRaw('
                sla_metric,
                AVG(compliance_percentage) as avg_compliance,
                MIN(compliance_percentage) as min_compliance,
                SUM(breach_count) as total_breaches
            ')
            ->groupBy('sla_metric')
            ->get()
            ->keyBy('sla_metric')
            ->toArray();
    }
}