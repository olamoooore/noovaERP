<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Modules\EventManagement\Services\TenantObservabilityService;
use App\Http\Controllers\Controller;

/**
 * ObservabilityDashboardController - Task 11.3
 * 
 * Provides tenant-specific health and performance dashboards, API usage monitoring,
 * queue health visualization, and automated alerting workflows for multi-tenant
 * observability management.
 * 
 * Requirements: 27.3, 27.4, 27.5
 */
class ObservabilityDashboardController extends Controller
{
    private TenantObservabilityService $observabilityService;

    public function __construct(TenantObservabilityService $observabilityService)
    {
        $this->observabilityService = $observabilityService;
    }

    /**
     * Get tenant observability dashboard
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getDashboard(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant_id' => 'required|string|max:100',
            'time_range' => 'sometimes|string|in:15m,1h,6h,24h,7d,30d',
            'refresh_cache' => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'details' => $validator->errors()
            ], 400);
        }

        $tenantId = $request->input('tenant_id');
        $timeRange = $request->input('time_range', '1h');
        $refreshCache = $request->input('refresh_cache', false);

        $options = [
            'time_range' => $timeRange,
            'refresh_cache' => $refreshCache
        ];

        $dashboard = $this->observabilityService->getTenantDashboard($tenantId, $options);

        if (!$dashboard['success']) {
            return response()->json($dashboard, 500);
        }

        return response()->json($dashboard);
    }

    /**
     * Get tenant health metrics
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getHealthMetrics(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant_id' => 'required|string|max:100',
            'metric_names' => 'sometimes|array',
            'metric_names.*' => 'string|max:100',
            'time_range' => 'sometimes|string|in:15m,1h,6h,24h,7d,30d',
            'aggregation' => 'sometimes|string|in:avg,min,max,sum,count'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'details' => $validator->errors()
            ], 400);
        }

        $tenantId = $request->input('tenant_id');
        $metricNames = $request->input('metric_names', []);
        $timeRange = $request->input('time_range', '1h');
        $aggregation = $request->input('aggregation', 'avg');

        try {
            $startTime = $this->calculateStartTime($timeRange);
            
            $query = \DB::table('tenant_health_metrics')
                ->where('tenant_id', $tenantId)
                ->where('collected_at', '>=', $startTime);

            if (!empty($metricNames)) {
                $query->whereIn('metric_name', $metricNames);
            }

            $metrics = $query->selectRaw("
                    metric_name,
                    metric_category,
                    {$aggregation}(metric_value) as aggregated_value,
                    metric_unit,
                    alert_status,
                    COUNT(*) as sample_count,
                    MIN(collected_at) as first_sample,
                    MAX(collected_at) as last_sample
                ")
                ->groupBy(['metric_name', 'metric_category', 'metric_unit', 'alert_status'])
                ->orderBy('metric_name')
                ->get();

            return response()->json([
                'success' => true,
                'tenant_id' => $tenantId,
                'time_range' => $timeRange,
                'aggregation' => $aggregation,
                'metrics' => $metrics,
                'total_metrics' => $metrics->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve health metrics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get API performance analytics
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getApiPerformance(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant_id' => 'required|string|max:100',
            'time_range' => 'sometimes|string|in:15m,1h,6h,24h,7d,30d',
            'endpoints' => 'sometimes|array',
            'endpoints.*' => 'string|max:255',
            'status_codes' => 'sometimes|array',
            'status_codes.*' => 'integer|min:100|max:599'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'details' => $validator->errors()
            ], 400);
        }

        $tenantId = $request->input('tenant_id');
        $timeRange = $request->input('time_range', '1h');
        $endpoints = $request->input('endpoints', []);
        $statusCodes = $request->input('status_codes', []);

        try {
            $startTime = $this->calculateStartTime($timeRange);
            
            $query = \DB::table('api_performance_log')
                ->where('tenant_id', $tenantId)
                ->where('request_started_at', '>=', $startTime);

            if (!empty($endpoints)) {
                $query->whereIn('endpoint', $endpoints);
            }

            if (!empty($statusCodes)) {
                $query->whereIn('response_status', $statusCodes);
            }

            // Overall performance summary
            $summary = $query->selectRaw('
                    COUNT(*) as total_requests,
                    AVG(response_time_ms) as avg_response_time,
                    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY response_time_ms) as p50_response_time,
                    PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY response_time_ms) as p95_response_time,
                    PERCENTILE_CONT(0.99) WITHIN GROUP (ORDER BY response_time_ms) as p99_response_time,
                    MAX(response_time_ms) as max_response_time,
                    COUNT(CASE WHEN response_status >= 400 THEN 1 END) as error_count,
                    COUNT(CASE WHEN response_status >= 500 THEN 1 END) as server_error_count,
                    AVG(request_size_bytes) as avg_request_size,
                    AVG(response_size_bytes) as avg_response_size
                ')
                ->first();

            // Performance by endpoint
            $endpointPerformance = \DB::table('api_performance_log')
                ->where('tenant_id', $tenantId)
                ->where('request_started_at', '>=', $startTime)
                ->selectRaw('
                    endpoint,
                    http_method,
                    COUNT(*) as request_count,
                    AVG(response_time_ms) as avg_response_time,
                    MAX(response_time_ms) as max_response_time,
                    COUNT(CASE WHEN response_status >= 400 THEN 1 END) as error_count,
                    (COUNT(CASE WHEN response_status >= 400 THEN 1 END) * 100.0 / COUNT(*)) as error_rate_percent
                ')
                ->groupBy(['endpoint', 'http_method'])
                ->orderBy('request_count', 'desc')
                ->limit(20)
                ->get();

            // Performance trends (hourly buckets)
            $trends = \DB::table('api_performance_log')
                ->where('tenant_id', $tenantId)
                ->where('request_started_at', '>=', $startTime)
                ->selectRaw("
                    DATE_TRUNC('hour', request_started_at) as time_bucket,
                    COUNT(*) as request_count,
                    AVG(response_time_ms) as avg_response_time,
                    COUNT(CASE WHEN response_status >= 400 THEN 1 END) as error_count
                ")
                ->groupBy('time_bucket')
                ->orderBy('time_bucket')
                ->get();

            return response()->json([
                'success' => true,
                'tenant_id' => $tenantId,
                'time_range' => $timeRange,
                'summary' => $summary,
                'endpoint_performance' => $endpointPerformance,
                'trends' => $trends
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve API performance data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get queue health dashboard
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getQueueHealth(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant_id' => 'required|string|max:100',
            'time_range' => 'sometimes|string|in:15m,1h,6h,24h,7d,30d',
            'queue_names' => 'sometimes|array',
            'queue_names.*' => 'string|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'details' => $validator->errors()
            ], 400);
        }

        $tenantId = $request->input('tenant_id');
        $timeRange = $request->input('time_range', '1h');
        $queueNames = $request->input('queue_names', []);

        // Get current queue health
        $queueHealth = $this->observabilityService->monitorQueueHealth($tenantId, [
            'queues' => $queueNames
        ]);

        if (!$queueHealth['success']) {
            return response()->json($queueHealth, 500);
        }

        try {
            $startTime = $this->calculateStartTime($timeRange);
            
            $query = \DB::table('queue_health_metrics')
                ->where('tenant_id', $tenantId)
                ->where('measured_at', '>=', $startTime);

            if (!empty($queueNames)) {
                $query->whereIn('queue_name', $queueNames);
            }

            // Historical queue metrics
            $historical = $query->selectRaw('
                    queue_name,
                    AVG(pending_jobs) as avg_pending,
                    MAX(pending_jobs) as max_pending,
                    AVG(queue_lag_seconds) as avg_lag,
                    MAX(queue_lag_seconds) as max_lag,
                    AVG(error_rate_percent) as avg_error_rate,
                    MAX(error_rate_percent) as max_error_rate,
                    AVG(throughput_per_minute) as avg_throughput
                ')
                ->groupBy('queue_name')
                ->get();

            // Queue trends
            $trends = \DB::table('queue_health_metrics')
                ->where('tenant_id', $tenantId)
                ->where('measured_at', '>=', $startTime)
                ->selectRaw("
                    DATE_TRUNC('hour', measured_at) as time_bucket,
                    queue_name,
                    AVG(pending_jobs) as avg_pending,
                    AVG(queue_lag_seconds) as avg_lag,
                    AVG(error_rate_percent) as avg_error_rate
                ")
                ->groupBy(['time_bucket', 'queue_name'])
                ->orderBy(['time_bucket', 'queue_name'])
                ->get();

            return response()->json([
                'success' => true,
                'tenant_id' => $tenantId,
                'time_range' => $timeRange,
                'current_health' => $queueHealth['queue_health'],
                'overall_health' => $queueHealth['overall_health'],
                'historical_metrics' => $historical,
                'trends' => $trends
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve queue health data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get active alerts and incidents
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getAlerts(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant_id' => 'required|string|max:100',
            'status' => 'sometimes|string|in:triggered,acknowledged,investigating,resolved,closed,suppressed',
            'severity' => 'sometimes|string|in:info,warning,critical',
            'limit' => 'sometimes|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'details' => $validator->errors()
            ], 400);
        }

        $tenantId = $request->input('tenant_id');
        $status = $request->input('status');
        $severity = $request->input('severity');
        $limit = $request->input('limit', 50);

        try {
            $query = \DB::table('alert_incidents')
                ->where('tenant_id', $tenantId);

            if ($status) {
                $query->where('incident_status', $status);
            }

            if ($severity) {
                $query->where('severity_level', $severity);
            }

            $incidents = $query->orderBy('triggered_at', 'desc')
                ->limit($limit)
                ->get();

            // Get alert statistics
            $stats = \DB::table('alert_incidents')
                ->where('tenant_id', $tenantId)
                ->where('triggered_at', '>=', now()->subDay())
                ->selectRaw('
                    COUNT(*) as total_incidents,
                    COUNT(CASE WHEN incident_status = "triggered" THEN 1 END) as active_incidents,
                    COUNT(CASE WHEN severity_level = "critical" THEN 1 END) as critical_incidents,
                    COUNT(CASE WHEN severity_level = "warning" THEN 1 END) as warning_incidents,
                    AVG(CASE WHEN resolved_at IS NOT NULL THEN 
                        EXTRACT(EPOCH FROM (resolved_at - triggered_at))/60 
                    END) as avg_resolution_time_minutes
                ')
                ->first();

            return response()->json([
                'success' => true,
                'tenant_id' => $tenantId,
                'incidents' => $incidents,
                'statistics' => $stats,
                'filters' => [
                    'status' => $status,
                    'severity' => $severity,
                    'limit' => $limit
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve alerts',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create or update alert rule
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function createAlertRule(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant_id' => 'required|string|max:100',
            'rule_name' => 'required|string|max:100',
            'rule_type' => 'required|string|in:threshold,anomaly,trend,composite',
            'metric_name' => 'required|string|max:100',
            'metric_category' => 'required|string|max:50',
            'conditions' => 'required|array',
            'conditions.warning_threshold' => 'sometimes|numeric',
            'conditions.critical_threshold' => 'sometimes|numeric',
            'severity_level' => 'required|string|in:info,warning,critical',
            'is_enabled' => 'sometimes|boolean',
            'evaluation_interval' => 'sometimes|integer|min:30|max:3600',
            'cooldown_seconds' => 'sometimes|integer|min:60|max:7200',
            'notification_channels' => 'sometimes|array',
            'notification_templates' => 'sometimes|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'details' => $validator->errors()
            ], 400);
        }

        $tenantId = $request->input('tenant_id');
        $ruleData = $request->only([
            'rule_name', 'rule_type', 'metric_name', 'metric_category',
            'conditions', 'severity_level', 'is_enabled', 'evaluation_interval',
            'cooldown_seconds', 'notification_channels', 'notification_templates'
        ]);

        $result = $this->observabilityService->createAlertRule($tenantId, $ruleData);

        if (!$result['success']) {
            return response()->json($result, 500);
        }

        return response()->json($result, 201);
    }

    /**
     * Get resource usage analytics
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getResourceUsage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant_id' => 'required|string|max:100',
            'time_range' => 'sometimes|string|in:15m,1h,6h,24h,7d,30d',
            'resource_types' => 'sometimes|array',
            'resource_types.*' => 'string|in:cpu,memory,storage,network,database'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'details' => $validator->errors()
            ], 400);
        }

        $tenantId = $request->input('tenant_id');
        $timeRange = $request->input('time_range', '24h');
        $resourceTypes = $request->input('resource_types', []);

        try {
            $startTime = $this->calculateStartTime($timeRange);
            
            $query = \DB::table('tenant_resource_usage')
                ->where('tenant_id', $tenantId)
                ->where('measured_at', '>=', $startTime);

            if (!empty($resourceTypes)) {
                $query->whereIn('resource_type', $resourceTypes);
            }

            $usage = $query->selectRaw('
                    resource_type,
                    usage_unit,
                    AVG(usage_value) as avg_usage,
                    MAX(usage_value) as max_usage,
                    MIN(usage_value) as min_usage,
                    AVG(usage_percentage) as avg_usage_percent,
                    MAX(usage_percentage) as max_usage_percent,
                    AVG(allocated_limit) as avg_limit,
                    SUM(cost_estimate) as total_estimated_cost
                ')
                ->groupBy(['resource_type', 'usage_unit'])
                ->get();

            // Get usage trends
            $trends = \DB::table('tenant_resource_usage')
                ->where('tenant_id', $tenantId)
                ->where('measured_at', '>=', $startTime)
                ->selectRaw("
                    DATE_TRUNC('hour', measured_at) as time_bucket,
                    resource_type,
                    AVG(usage_value) as avg_usage,
                    AVG(usage_percentage) as avg_usage_percent
                ")
                ->groupBy(['time_bucket', 'resource_type'])
                ->orderBy(['time_bucket', 'resource_type'])
                ->get();

            return response()->json([
                'success' => true,
                'tenant_id' => $tenantId,
                'time_range' => $timeRange,
                'resource_usage' => $usage,
                'usage_trends' => $trends
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve resource usage data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate start time for time range
     */
    private function calculateStartTime(string $timeRange): \Carbon\Carbon
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
}