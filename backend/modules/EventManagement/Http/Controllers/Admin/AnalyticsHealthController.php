<?php

namespace Modules\EventManagement\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\EventManagement\Services\DataHealthCheckService;
use Modules\EventManagement\Services\HybridAnalyticsService;
use Carbon\Carbon;

class AnalyticsHealthController extends Controller
{
    private DataHealthCheckService $healthCheckService;
    private HybridAnalyticsService $analyticsService;

    public function __construct(
        DataHealthCheckService $healthCheckService,
        HybridAnalyticsService $analyticsService
    ) {
        $this->healthCheckService = $healthCheckService;
        $this->analyticsService = $analyticsService;
    }

    /**
     * Get analytics health dashboard data
     */
    public function dashboard(): JsonResponse
    {
        try {
            $summary = $this->healthCheckService->getHealthCheckSummary();
            $recentChecks = $this->healthCheckService->getRecentHealthCheckResults(24);
            $kpiDefinitions = $this->healthCheckService->getKPIDefinitions();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => $summary,
                    'recent_checks' => $recentChecks,
                    'kpi_definitions' => $kpiDefinitions,
                    'last_updated' => Carbon::now()->toISOString()
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load analytics health dashboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Run health checks manually
     */
    public function runHealthChecks(): JsonResponse
    {
        try {
            $results = $this->healthCheckService->runDataHealthChecks();
            
            return response()->json([
                'success' => true,
                'message' => 'Health checks completed successfully',
                'data' => $results
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Health checks failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed health check results
     */
    public function getHealthCheckDetails(Request $request): JsonResponse
    {
        try {
            $hours = $request->get('hours', 24);
            $checkName = $request->get('check_name');
            $status = $request->get('status');
            
            $results = $this->healthCheckService->getRecentHealthCheckResults($hours);
            
            // Filter by check name if provided
            if ($checkName) {
                $results = array_filter($results, function ($check) use ($checkName) {
                    return $check->check_name === $checkName;
                });
            }
            
            // Filter by status if provided
            if ($status) {
                $results = array_filter($results, function ($check) use ($status) {
                    return $check->status === $status;
                });
            }
            
            return response()->json([
                'success' => true,
                'data' => array_values($results)
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get health check details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get KPI definitions with management interface
     */
    public function getKPIDefinitions(): JsonResponse
    {
        try {
            $definitions = $this->healthCheckService->getKPIDefinitions();
            
            return response()->json([
                'success' => true,
                'data' => $definitions
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get KPI definitions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create or update KPI definition
     */
    public function saveKPIDefinition(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'kpi_name' => 'required|string|max:100',
                'kpi_description' => 'required|string',
                'calculation_logic' => 'required|array',
                'data_sources' => 'required|array',
                'refresh_frequency' => 'required|in:real_time,hourly,daily,weekly',
                'version' => 'integer|min:1',
                'is_active' => 'boolean'
            ]);
            
            $kpiId = $this->healthCheckService->registerKPIDefinition($request->all());
            
            return response()->json([
                'success' => true,
                'message' => 'KPI definition saved successfully',
                'data' => ['kpi_id' => $kpiId]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save KPI definition',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get analytics reconciliation report
     */
    public function getReconciliationReport(Request $request): JsonResponse
    {
        try {
            $startDate = Carbon::parse($request->get('start_date', Carbon::now()->subDays(7)));
            $endDate = Carbon::parse($request->get('end_date', Carbon::now()));
            $metric = $request->get('metric', 'booking_revenue');
            
            // Get hybrid analytics data
            $analyticsData = $this->analyticsService->getHybridAnalytics($metric, [
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);
            
            // Get batch aggregation for comparison
            $batchData = $this->analyticsService->processBatchAggregation($metric, $startDate, $endDate);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'metric' => $metric,
                    'period' => [
                        'start_date' => $startDate->toDateString(),
                        'end_date' => $endDate->toDateString()
                    ],
                    'hybrid_analytics' => $analyticsData,
                    'batch_aggregation' => $batchData,
                    'generated_at' => Carbon::now()->toISOString()
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate reconciliation report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Trigger analytics cache invalidation
     */
    public function invalidateCache(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'metric' => 'required|string',
                'date' => 'required|date'
            ]);
            
            $metric = $request->get('metric');
            $date = Carbon::parse($request->get('date'));
            
            $this->analyticsService->invalidateAnalyticsCache($metric, $date);
            
            return response()->json([
                'success' => true,
                'message' => 'Analytics cache invalidated successfully',
                'data' => [
                    'metric' => $metric,
                    'date' => $date->toDateString()
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to invalidate analytics cache',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Trigger historical data backfill
     */
    public function backfillHistoricalData(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'metric' => 'required|string',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date'
            ]);
            
            $metric = $request->get('metric');
            $startDate = Carbon::parse($request->get('start_date'));
            $endDate = Carbon::parse($request->get('end_date'));
            
            // Validate date range (prevent excessive backfills)
            if ($startDate->diffInDays($endDate) > 365) {
                return response()->json([
                    'success' => false,
                    'message' => 'Date range cannot exceed 365 days'
                ], 400);
            }
            
            // Queue backfill job (in production, this would be dispatched to a queue)
            $this->analyticsService->backfillHistoricalData($metric, $startDate, $endDate);
            
            return response()->json([
                'success' => true,
                'message' => 'Historical data backfill initiated successfully',
                'data' => [
                    'metric' => $metric,
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'estimated_days' => $startDate->diffInDays($endDate)
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate historical data backfill',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get data quality alerts and resolution workflows
     */
    public function getDataQualityAlerts(Request $request): JsonResponse
    {
        try {
            $severity = $request->get('severity', 'all'); // all, failed, warning
            $hours = $request->get('hours', 24);
            
            $results = $this->healthCheckService->getRecentHealthCheckResults($hours);
            
            // Filter by severity
            if ($severity !== 'all') {
                $results = array_filter($results, function ($check) use ($severity) {
                    return $check->status === $severity;
                });
            }
            
            // Group by check type for better organization
            $groupedAlerts = [];
            foreach ($results as $check) {
                $groupedAlerts[$check->check_type][] = [
                    'check_name' => $check->check_name,
                    'status' => $check->status,
                    'target_table' => $check->target_table,
                    'expected_result' => json_decode($check->expected_result, true),
                    'actual_result' => json_decode($check->actual_result, true),
                    'checked_at' => $check->checked_at,
                    'resolution_guidance' => $this->getResolutionGuidance($check->check_name, $check->status)
                ];
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'alerts_by_type' => $groupedAlerts,
                    'total_alerts' => count($results),
                    'severity_filter' => $severity,
                    'time_range_hours' => $hours
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get data quality alerts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get resolution guidance for specific check failures
     */
    private function getResolutionGuidance(string $checkName, string $status): array
    {
        if ($status === 'passed') {
            return ['message' => 'No action required'];
        }
        
        $guidance = [
            'booking_analytics_reconciliation' => [
                'failed' => [
                    'message' => 'Significant discrepancy between booking data and analytics',
                    'actions' => [
                        'Check for failed analytics fact insertions',
                        'Verify booking status updates are triggering analytics updates',
                        'Run manual reconciliation for affected time period',
                        'Check for database replication lag'
                    ]
                ],
                'warning' => [
                    'message' => 'Minor discrepancy detected in analytics reconciliation',
                    'actions' => [
                        'Monitor for trend over next few hours',
                        'Check for recent system deployments',
                        'Verify analytics processing jobs are running'
                    ]
                ]
            ],
            'capacity_consistency' => [
                'failed' => [
                    'message' => 'Capacity constraints violated - potential overselling detected',
                    'actions' => [
                        'URGENT: Review affected events immediately',
                        'Check capacity reservation cleanup job status',
                        'Verify optimistic locking is working correctly',
                        'Consider temporary booking suspension for affected events'
                    ]
                ]
            ],
            'pii_vault_integrity' => [
                'failed' => [
                    'message' => 'PII vault integrity issues detected',
                    'actions' => [
                        'Check encryption key status and rotation',
                        'Verify PII access logging is functioning',
                        'Run anonymization job for expired data',
                        'Review access patterns for anomalies'
                    ]
                ]
            ],
            'outbox_processing_health' => [
                'failed' => [
                    'message' => 'Outbox event processing issues detected',
                    'actions' => [
                        'Check outbox processor job status',
                        'Review failed events for patterns',
                        'Verify message queue connectivity',
                        'Consider manual retry for stuck events'
                    ]
                ]
            ]
        ];
        
        return $guidance[$checkName][$status] ?? [
            'message' => 'Generic resolution guidance',
            'actions' => [
                'Review system logs for errors',
                'Check related service health',
                'Contact system administrator if issue persists'
            ]
        ];
    }
}