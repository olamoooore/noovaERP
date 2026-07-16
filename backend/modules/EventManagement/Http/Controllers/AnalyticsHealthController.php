<?php

namespace Modules\EventManagement\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\EventManagement\Services\DataHealthCheckService;
use Modules\EventManagement\Services\HybridAnalyticsService;

/**
 * AnalyticsHealthController - Task 5.4
 *
 * Provides admin interface for data health check results, KPI definition management
 * and versioning UI, analytics reconciliation reporting, and data quality alerts
 * and resolution workflows.
 *
 * Requirements: 21.5
 */
class AnalyticsHealthController extends Controller
{
    protected DataHealthCheckService $healthCheckService;

    protected HybridAnalyticsService $hybridAnalyticsService;

    public function __construct(
        DataHealthCheckService $healthCheckService,
        HybridAnalyticsService $hybridAnalyticsService
    ) {
        $this->healthCheckService = $healthCheckService;
        $this->hybridAnalyticsService = $hybridAnalyticsService;
    }

    /**
     * Get analytics health dashboard overview.
     */
    public function getDashboardOverview(): JsonResponse
    {
        try {
            // Get health check summary
            $healthCheckSummary = $this->getHealthCheckSummary();

            // Get KPI registry summary
            $kpiSummary = $this->getKPISummary();

            // Get recent alerts
            $recentAlerts = $this->getRecentAlerts();

            // Get reconciliation summary
            $reconciliationSummary = $this->getReconciliationSummary();

            // Get performance metrics
            $performanceMetrics = $this->getPerformanceMetrics();

            return response()->json([
                'success' => true,
                'dashboard' => [
                    'health_checks' => $healthCheckSummary,
                    'kpis' => $kpiSummary,
                    'alerts' => $recentAlerts,
                    'reconciliation' => $reconciliationSummary,
                    'performance' => $performanceMetrics,
                    'last_updated' => Carbon::now()->toISOString(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed health check results.
     */
    public function getHealthCheckResults(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'target_table' => 'nullable|string',
                'check_type' => 'nullable|in:reconciliation,consistency,completeness,accuracy,freshness,uniqueness',
                'status' => 'nullable|in:passed,failed,warning,skipped',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 400);
            }

            $query = DB::table('data_health_checks')
                ->select([
                    'id',
                    'check_name',
                    'check_type',
                    'target_table',
                    'target_column',
                    'status',
                    'expected_result',
                    'actual_result',
                    'threshold_warning',
                    'threshold_critical',
                    'failure_reason',
                    'last_checked_at',
                    'created_at',
                ]);

            // Apply filters
            if ($request->filled('target_table')) {
                $query->where('target_table', $request->target_table);
            }

            if ($request->filled('check_type')) {
                $query->where('check_type', $request->check_type);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Pagination
            $perPage = $request->get('per_page', 20);
            $page = $request->get('page', 1);
            $offset = ($page - 1) * $perPage;

            $total = $query->count();
            $healthChecks = $query->orderBy('last_checked_at', 'desc')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            // Format results
            $formattedResults = $healthChecks->map(function ($check) {
                return [
                    'id' => $check->id,
                    'check_name' => $check->check_name,
                    'check_type' => $check->check_type,
                    'target_table' => $check->target_table,
                    'target_column' => $check->target_column,
                    'status' => $check->status,
                    'expected_result' => $check->expected_result ? json_decode($check->expected_result, true) : null,
                    'actual_result' => $check->actual_result ? json_decode($check->actual_result, true) : null,
                    'threshold_warning' => $check->threshold_warning,
                    'threshold_critical' => $check->threshold_critical,
                    'failure_reason' => $check->failure_reason,
                    'last_checked_at' => $check->last_checked_at,
                    'created_at' => $check->created_at,
                ];
            });

            return response()->json([
                'success' => true,
                'health_checks' => $formattedResults,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Execute health checks manually.
     */
    public function executeHealthChecks(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'target_table' => 'nullable|string',
                'check_types' => 'nullable|array',
                'check_types.*' => 'in:reconciliation,consistency,completeness,accuracy,freshness,uniqueness',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 400);
            }

            $result = $this->healthCheckService->executeHealthChecks(
                $request->target_table,
                $request->get('check_types', [])
            );

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get KPI registry with management interface.
     */
    public function getKPIRegistry(Request $request): JsonResponse
    {
        try {
            $activeOnly = $request->get('active_only', true);
            $kpis = $this->healthCheckService->getKPIRegistry($activeOnly);

            // Group by KPI name to show versions
            $groupedKPIs = [];
            foreach ($kpis as $kpi) {
                $name = $kpi['name'];
                if (! isset($groupedKPIs[$name])) {
                    $groupedKPIs[$name] = [
                        'name' => $name,
                        'current_version' => $kpi,
                        'versions' => [],
                    ];
                }
                $groupedKPIs[$name]['versions'][] = $kpi;
            }

            return response()->json([
                'success' => true,
                'kpi_registry' => array_values($groupedKPIs),
                'total_kpis' => count($groupedKPIs),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create or update KPI definition.
     */
    public function createOrUpdateKPI(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'kpi_name' => 'required|string|max:100',
                'description' => 'required|string',
                'calculation_logic' => 'required|array',
                'data_sources' => 'required|array',
                'refresh_frequency' => 'required|in:real_time,hourly,daily,weekly',
                'owner_team' => 'nullable|string|max:100',
                'business_domain' => 'nullable|string|max:100',
                'validation_rules' => 'nullable|array',
                'criticality' => 'nullable|in:low,medium,high,critical',
                'metadata' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 400);
            }

            $result = $this->healthCheckService->registerKPI($request->all());

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get reconciliation reports.
     */
    public function getReconciliationReports(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'reconciliation_type' => 'nullable|string',
                'status' => 'nullable|in:match,variance_acceptable,variance_critical,failed',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 400);
            }

            $query = DB::table('analytics_reconciliation_log')
                ->select([
                    'id',
                    'reconciliation_type',
                    'source_entity',
                    'target_entity',
                    'variance_percentage',
                    'variance_absolute',
                    'status',
                    'variance_explanation',
                    'remediation_actions',
                    'reconciled_at',
                    'reconciled_by',
                ]);

            // Apply filters
            if ($request->filled('reconciliation_type')) {
                $query->where('reconciliation_type', $request->reconciliation_type);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('date_from')) {
                $query->where('reconciled_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->where('reconciled_at', '<=', $request->date_to);
            }

            // Pagination
            $perPage = $request->get('per_page', 20);
            $page = $request->get('page', 1);
            $offset = ($page - 1) * $perPage;

            $total = $query->count();
            $reconciliations = $query->orderBy('reconciled_at', 'desc')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            // Format results
            $formattedResults = $reconciliations->map(function ($reconciliation) {
                return [
                    'id' => $reconciliation->id,
                    'reconciliation_type' => $reconciliation->reconciliation_type,
                    'source_entity' => $reconciliation->source_entity,
                    'target_entity' => $reconciliation->target_entity,
                    'variance_percentage' => $reconciliation->variance_percentage,
                    'variance_absolute' => $reconciliation->variance_absolute,
                    'status' => $reconciliation->status,
                    'variance_explanation' => $reconciliation->variance_explanation,
                    'remediation_actions' => $reconciliation->remediation_actions ?
                        json_decode($reconciliation->remediation_actions, true) : [],
                    'reconciled_at' => $reconciliation->reconciled_at,
                    'reconciled_by' => $reconciliation->reconciled_by,
                ];
            });

            return response()->json([
                'success' => true,
                'reconciliations' => $formattedResults,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get data quality alerts.
     */
    public function getDataQualityAlerts(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'alert_level' => 'nullable|in:info,warning,critical',
                'status' => 'nullable|in:open,acknowledged,resolved,suppressed',
                'assigned_to' => 'nullable|string',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 400);
            }

            $query = DB::table('data_quality_alerts')
                ->leftJoin('data_health_checks', 'data_quality_alerts.health_check_id', '=', 'data_health_checks.id')
                ->select([
                    'data_quality_alerts.id',
                    'data_quality_alerts.alert_level',
                    'data_quality_alerts.alert_title',
                    'data_quality_alerts.alert_description',
                    'data_quality_alerts.status',
                    'data_quality_alerts.assigned_to',
                    'data_quality_alerts.created_at',
                    'data_quality_alerts.acknowledged_at',
                    'data_quality_alerts.resolved_at',
                    'data_health_checks.check_name',
                    'data_health_checks.target_table',
                ]);

            // Apply filters
            if ($request->filled('alert_level')) {
                $query->where('data_quality_alerts.alert_level', $request->alert_level);
            }

            if ($request->filled('status')) {
                $query->where('data_quality_alerts.status', $request->status);
            }

            if ($request->filled('assigned_to')) {
                $query->where('data_quality_alerts.assigned_to', $request->assigned_to);
            }

            // Pagination
            $perPage = $request->get('per_page', 20);
            $page = $request->get('page', 1);
            $offset = ($page - 1) * $perPage;

            $total = $query->count();
            $alerts = $query->orderBy('data_quality_alerts.created_at', 'desc')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            return response()->json([
                'success' => true,
                'alerts' => $alerts,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update data quality alert status.
     */
    public function updateAlertStatus(Request $request, int $alertId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:acknowledged,resolved,suppressed',
                'assigned_to' => 'nullable|string',
                'resolution_notes' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 400);
            }

            $updateData = [
                'status' => $request->status,
                'updated_at' => Carbon::now(),
            ];

            if ($request->filled('assigned_to')) {
                $updateData['assigned_to'] = $request->assigned_to;
            }

            if ($request->filled('resolution_notes')) {
                $updateData['resolution_notes'] = $request->resolution_notes;
            }

            if ($request->status === 'acknowledged') {
                $updateData['acknowledged_at'] = Carbon::now();
            } elseif ($request->status === 'resolved') {
                $updateData['resolved_at'] = Carbon::now();
            }

            $updated = DB::table('data_quality_alerts')
                ->where('id', $alertId)
                ->update($updateData);

            if (! $updated) {
                return response()->json([
                    'success' => false,
                    'error' => 'Alert not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Alert status updated successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Private helper methods

    private function getHealthCheckSummary(): array
    {
        $summary = DB::table('data_health_checks')
            ->selectRaw('
                status,
                COUNT(*) as count,
                MAX(last_checked_at) as last_checked
            ')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return [
            'total' => $summary->sum('count'),
            'passed' => $summary->get('passed')->count ?? 0,
            'failed' => $summary->get('failed')->count ?? 0,
            'warning' => $summary->get('warning')->count ?? 0,
            'skipped' => $summary->get('skipped')->count ?? 0,
            'last_execution' => $summary->max('last_checked'),
        ];
    }

    private function getKPISummary(): array
    {
        $summary = DB::table('kpi_definitions')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                COUNT(DISTINCT kpi_name) as unique_kpis
            ')
            ->first();

        return [
            'total_definitions' => $summary->total,
            'active_definitions' => $summary->active,
            'unique_kpis' => $summary->unique_kpis,
        ];
    }

    private function getRecentAlerts(): array
    {
        return DB::table('data_quality_alerts')
            ->select(['id', 'alert_level', 'alert_title', 'status', 'created_at'])
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->toArray();
    }

    private function getReconciliationSummary(): array
    {
        $summary = DB::table('analytics_reconciliation_log')
            ->selectRaw('
                status,
                COUNT(*) as count,
                AVG(variance_percentage) as avg_variance
            ')
            ->where('reconciled_at', '>=', Carbon::now()->subDays(7))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return [
            'total_reconciliations' => $summary->sum('count'),
            'matches' => $summary->get('match')->count ?? 0,
            'acceptable_variance' => $summary->get('variance_acceptable')->count ?? 0,
            'critical_variance' => $summary->get('variance_critical')->count ?? 0,
            'failed' => $summary->get('failed')->count ?? 0,
        ];
    }

    private function getPerformanceMetrics(): array
    {
        return DB::table('analytics_performance_metrics')
            ->selectRaw('
                operation_type,
                AVG(execution_time_ms) as avg_execution_time,
                MAX(execution_time_ms) as max_execution_time,
                COUNT(*) as operation_count
            ')
            ->where('measured_at', '>=', Carbon::now()->subDays(1))
            ->groupBy('operation_type')
            ->get()
            ->toArray();
    }
}
