<?php

namespace Modules\IoT\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\IoT\Models\IoTDevice;
use Modules\IoT\Models\IoTDeviceHealthLog;
use Modules\IoT\Models\IoTDeviceMetric;
use Modules\IoT\Services\HealthCheckService;
use Modules\IoT\Jobs\CheckDeviceHealthJob;

class IoTMonitoringController extends Controller
{
    public function __construct(
        protected HealthCheckService $healthCheckService
    ) {}

    /**
     * Get monitoring dashboard data
     */
    public function dashboard(): JsonResponse
    {
        $dashboardData = $this->healthCheckService->getHealthDashboard();
        
        return response()->json($dashboardData);
    }

    /**
     * Get health summary for a specific device
     */
    public function deviceHealth(Request $request, $device): JsonResponse
    {
        $device = IoTDevice::findOrFail($device);
        $days = $request->input('days', 7);
        $healthSummary = $this->healthCheckService->getDeviceHealthSummary($device, $days);
        
        return response()->json($healthSummary);
    }

    /**
     * Get health logs for a device
     */
    public function deviceHealthLogs(Request $request, $device): JsonResponse
    {
        $device = IoTDevice::findOrFail($device);
        $query = IoTDeviceHealthLog::where('iot_device_id', $device->id);

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('checked_at', '>=', $request->start_date);
        }
        
        if ($request->has('end_date')) {
            $query->where('checked_at', '<=', $request->end_date);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $logs = $query->orderBy('checked_at', 'desc')
                     ->paginate($request->input('per_page', 50));

        return response()->json($logs);
    }

    /**
     * Get device metrics
     */
    public function deviceMetrics(Request $request, $device): JsonResponse
    {
        $device = IoTDevice::findOrFail($device);
        $days = $request->input('days', 30);
        
        $metrics = IoTDeviceMetric::where('iot_device_id', $device->id)
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('date', 'desc')
            ->get();

        return response()->json($metrics);
    }

    /**
     * Trigger manual health check for a device
     */
    public function checkDevice($device): JsonResponse
    {
        $device = IoTDevice::findOrFail($device);
        try {
            $result = $this->healthCheckService->checkDevice($device);
            
            return response()->json([
                'message' => 'Health check completed',
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Health check failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Trigger health check for all devices (async)
     */
    public function checkAllDevices(): JsonResponse
    {
        $activeDevices = IoTDevice::where('is_active', true)->count();
        
        // Queue health check job for all devices
        CheckDeviceHealthJob::dispatch();
        
        return response()->json([
            'message' => 'Health check queued for all devices',
            'device_count' => $activeDevices,
        ]);
    }

    /**
     * Get overall system health statistics
     */
    public function systemHealth(): JsonResponse
    {
        $totalDevices = IoTDevice::where('is_active', true)->count();
        $onlineDevices = IoTDevice::where('is_active', true)->where('status', 'online')->count();
        
        // Get recent performance data
        $recentMetrics = IoTDeviceMetric::where('date', '>=', now()->subDays(7)->toDateString())
            ->selectRaw('
                AVG(uptime_percentage) as avg_uptime,
                AVG(avg_response_time_ms) as avg_response_time,
                SUM(total_operations) as total_operations,
                SUM(successful_operations) as successful_operations
            ')
            ->first();

        // Get alert counts
        $criticalAlerts = IoTDeviceHealthLog::where('status', 'error')
            ->where('checked_at', '>=', now()->subHour())
            ->count();

        $warningAlerts = IoTDeviceHealthLog::where('status', 'offline')
            ->where('checked_at', '>=', now()->subHour())
            ->count();

        return response()->json([
            'system_status' => $totalDevices > 0 && ($onlineDevices / $totalDevices) >= 0.8 ? 'healthy' : 'degraded',
            'total_devices' => $totalDevices,
            'online_devices' => $onlineDevices,
            'offline_devices' => $totalDevices - $onlineDevices,
            'overall_uptime' => $totalDevices > 0 ? round(($onlineDevices / $totalDevices) * 100, 2) : 0,
            'performance' => [
                'avg_uptime' => $recentMetrics->avg_uptime ? round($recentMetrics->avg_uptime, 2) : null,
                'avg_response_time' => $recentMetrics->avg_response_time ? (int) $recentMetrics->avg_response_time : null,
                'operation_success_rate' => $recentMetrics->total_operations > 0 
                    ? round(($recentMetrics->successful_operations / $recentMetrics->total_operations) * 100, 2) 
                    : null,
            ],
            'alerts' => [
                'critical' => $criticalAlerts,
                'warning' => $warningAlerts,
            ],
        ]);
    }

    /**
     * Get performance trends
     */
    public function performanceTrends(Request $request): JsonResponse
    {
        $days = $request->input('days', 30);
        
        $trends = IoTDeviceMetric::selectRaw('
                date,
                AVG(uptime_percentage) as avg_uptime,
                AVG(avg_response_time_ms) as avg_response_time,
                SUM(total_operations) as total_operations,
                SUM(successful_operations) as successful_operations,
                COUNT(DISTINCT iot_device_id) as active_devices
            ')
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($trend) {
                return [
                    'date' => $trend->date,
                    'avg_uptime' => $trend->avg_uptime ? round($trend->avg_uptime, 2) : 0,
                    'avg_response_time' => $trend->avg_response_time ? (int) $trend->avg_response_time : 0,
                    'operation_success_rate' => $trend->total_operations > 0 
                        ? round(($trend->successful_operations / $trend->total_operations) * 100, 2) 
                        : 0,
                    'active_devices' => $trend->active_devices,
                ];
            });

        return response()->json($trends);
    }

    /**
     * Get recent alerts
     */
    public function recentAlerts(Request $request): JsonResponse
    {
        $hours = $request->input('hours', 24);
        
        $alerts = IoTDeviceHealthLog::with('device')
            ->whereIn('status', ['offline', 'error', 'timeout'])
            ->where('checked_at', '>=', now()->subHours($hours))
            ->orderBy('checked_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'device_id' => $log->device->id,
                    'device_name' => $log->device->name,
                    'device_type' => $log->device->type,
                    'status' => $log->status,
                    'error_message' => $log->error_message,
                    'checked_at' => $log->checked_at,
                    'severity' => $log->status === 'error' ? 'critical' : 'warning',
                ];
            });

        return response()->json($alerts);
    }
}