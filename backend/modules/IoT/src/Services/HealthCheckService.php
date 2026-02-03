<?php

namespace Modules\IoT\Services;

use Modules\IoT\Models\IoTDevice;
use Modules\IoT\Models\IoTDeviceHealthLog;
use Modules\IoT\Models\IoTDeviceMetric;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class HealthCheckService
{
    /**
     * Perform health check on a single device
     */
    public function checkDevice(IoTDevice $device): IoTDeviceHealthLog
    {
        $startTime = microtime(true);
        $status = 'offline';
        $responseTime = null;
        $healthData = [];
        $errorMessage = null;

        try {
            $result = $this->performHealthCheck($device);
            
            $endTime = microtime(true);
            $responseTime = (int) (($endTime - $startTime) * 1000); // Convert to milliseconds
            
            $status = $result['status'];
            $healthData = $result['data'] ?? [];
            $errorMessage = $result['error'] ?? null;

        } catch (\Exception $e) {
            $endTime = microtime(true);
            $responseTime = (int) (($endTime - $startTime) * 1000);
            $status = 'error';
            $errorMessage = $e->getMessage();
            
            Log::warning("Health check failed for device {$device->id}: " . $e->getMessage());
        }

        // Create health log entry
        $healthLog = IoTDeviceHealthLog::create([
            'iot_device_id' => $device->id,
            'status' => $status,
            'response_time_ms' => $responseTime,
            'health_data' => $healthData,
            'error_message' => $errorMessage,
            'checked_at' => now(),
        ]);

        // Update device status
        $this->updateDeviceStatus($device, $status);

        // Update daily metrics
        $this->updateDailyMetrics($device, $status, $responseTime);

        return $healthLog;
    }

    /**
     * Perform health check on all active devices
     */
    public function checkAllDevices(): array
    {
        $devices = IoTDevice::where('is_active', true)->get();
        $results = [];

        foreach ($devices as $device) {
            $results[] = $this->checkDevice($device);
        }

        return $results;
    }

    /**
     * Get device health summary
     */
    public function getDeviceHealthSummary(IoTDevice $device, int $days = 7): array
    {
        $startDate = now()->subDays($days);
        
        // Get recent health logs
        $recentLogs = IoTDeviceHealthLog::where('iot_device_id', $device->id)
            ->where('checked_at', '>=', $startDate)
            ->orderBy('checked_at', 'desc')
            ->get();

        // Get daily metrics
        $metrics = IoTDeviceMetric::where('iot_device_id', $device->id)
            ->where('date', '>=', $startDate->toDateString())
            ->orderBy('date', 'desc')
            ->get();

        // Calculate summary statistics
        $totalChecks = $recentLogs->count();
        $successfulChecks = $recentLogs->where('status', 'online')->count();
        $avgResponseTime = $recentLogs->where('response_time_ms', '!=', null)->avg('response_time_ms');
        $uptime = $totalChecks > 0 ? ($successfulChecks / $totalChecks) * 100 : 0;

        return [
            'device_id' => $device->id,
            'current_status' => $device->status,
            'last_seen' => $device->last_seen_at,
            'summary' => [
                'total_checks' => $totalChecks,
                'successful_checks' => $successfulChecks,
                'uptime_percentage' => round($uptime, 2),
                'avg_response_time_ms' => $avgResponseTime ? (int) $avgResponseTime : null,
            ],
            'recent_logs' => $recentLogs->take(10)->values(),
            'daily_metrics' => $metrics->values(),
        ];
    }

    /**
     * Get overall health dashboard data
     */
    public function getHealthDashboard(): array
    {
        $totalDevices = IoTDevice::where('is_active', true)->count();
        $onlineDevices = IoTDevice::where('is_active', true)->where('status', 'online')->count();
        $offlineDevices = IoTDevice::where('is_active', true)->where('status', 'offline')->count();
        $errorDevices = IoTDevice::where('is_active', true)->where('status', 'error')->count();

        // Get recent alerts (devices that went offline in last 24 hours)
        $recentAlerts = IoTDeviceHealthLog::with('device')
            ->where('status', 'offline')
            ->where('checked_at', '>=', now()->subDay())
            ->orderBy('checked_at', 'desc')
            ->limit(10)
            ->get();

        // Get performance trends (last 7 days)
        $performanceTrends = IoTDeviceMetric::selectRaw('
                date,
                AVG(uptime_percentage) as avg_uptime,
                AVG(avg_response_time_ms) as avg_response_time,
                SUM(total_operations) as total_operations,
                SUM(successful_operations) as successful_operations
            ')
            ->where('date', '>=', now()->subDays(7)->toDateString())
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'overview' => [
                'total_devices' => $totalDevices,
                'online_devices' => $onlineDevices,
                'offline_devices' => $offlineDevices,
                'error_devices' => $errorDevices,
                'overall_uptime' => $totalDevices > 0 ? round(($onlineDevices / $totalDevices) * 100, 2) : 0,
            ],
            'recent_alerts' => $recentAlerts,
            'performance_trends' => $performanceTrends,
        ];
    }

    /**
     * Perform the actual health check based on device type and connection
     */
    private function performHealthCheck(IoTDevice $device): array
    {
        $connectionType = $device->connection_type instanceof \BackedEnum 
            ? $device->connection_type->value 
            : $device->connection_type;

        switch ($connectionType) {
            case 'lan':
                return $this->checkLanDevice($device);
            case 'cloud':
                return $this->checkCloudDevice($device);
            case 'usb':
            case 'agent':
                return $this->checkAgentDevice($device);
            default:
                throw new \Exception("Unsupported connection type: {$connectionType}");
        }
    }

    /**
     * Check LAN-connected device
     */
    private function checkLanDevice(IoTDevice $device): array
    {
        $config = $device->config;
        $ip = $config['ip'] ?? null;
        $port = $config['port'] ?? 9100;

        if (!$ip) {
            throw new \Exception('No IP address configured for LAN device');
        }

        // Try to connect to the device
        $connection = @fsockopen($ip, $port, $errno, $errstr, 5);
        
        if (!$connection) {
            return [
                'status' => 'offline',
                'error' => "Cannot connect to {$ip}:{$port} - {$errstr}",
            ];
        }

        fclose($connection);

        return [
            'status' => 'online',
            'data' => [
                'ip' => $ip,
                'port' => $port,
                'connection_method' => 'socket',
            ],
        ];
    }

    /**
     * Check cloud-connected device
     */
    private function checkCloudDevice(IoTDevice $device): array
    {
        $config = $device->config;
        $vendor = is_string($device->vendor) ? $device->vendor : (string) $device->vendor;
        
        // For cloud devices, we might ping their API or check their status
        // This is vendor-specific implementation
        switch (strtolower($vendor)) {
            case 'stripe':
                return $this->checkStripeDevice($device);
            case 'hitpay':
                return $this->checkHitPayDevice($device);
            default:
                // Generic cloud device check
                return [
                    'status' => 'online',
                    'data' => [
                        'vendor' => $vendor,
                        'connection_method' => 'cloud_api',
                    ],
                ];
        }
    }

    /**
     * Check agent-connected device
     */
    private function checkAgentDevice(IoTDevice $device): array
    {
        // For agent-connected devices, we would communicate with the local agent
        // For now, we'll simulate this
        return [
            'status' => 'online',
            'data' => [
                'agent_id' => $device->agent_id,
                'connection_method' => 'local_agent',
            ],
        ];
    }

    /**
     * Check Stripe device status
     */
    private function checkStripeDevice(IoTDevice $device): array
    {
        // In a real implementation, you would use Stripe's API to check device status
        // For now, we'll simulate this
        return [
            'status' => 'online',
            'data' => [
                'vendor' => 'stripe',
                'api_status' => 'connected',
            ],
        ];
    }

    /**
     * Check HitPay device status
     */
    private function checkHitPayDevice(IoTDevice $device): array
    {
        // In a real implementation, you would use HitPay's API to check device status
        return [
            'status' => 'online',
            'data' => [
                'vendor' => 'hitpay',
                'api_status' => 'connected',
            ],
        ];
    }

    /**
     * Update device status based on health check result
     */
    private function updateDeviceStatus(IoTDevice $device, string $status): void
    {
        $device->update([
            'status' => $status,
            'last_seen_at' => $status === 'online' ? now() : $device->last_seen_at,
        ]);
    }

    /**
     * Update daily metrics for the device
     */
    private function updateDailyMetrics(IoTDevice $device, string $status, ?int $responseTime): void
    {
        $today = now()->toDateString();
        
        $metric = IoTDeviceMetric::firstOrCreate(
            [
                'iot_device_id' => $device->id,
                'date' => $today,
            ],
            [
                'total_checks' => 0,
                'successful_checks' => 0,
                'failed_checks' => 0,
                'avg_response_time_ms' => null,
                'max_response_time_ms' => null,
                'uptime_percentage' => 0,
            ]
        );

        // Update check counts
        $metric->total_checks++;
        if ($status === 'online') {
            $metric->successful_checks++;
        } else {
            $metric->failed_checks++;
        }

        // Update response time metrics
        if ($responseTime !== null) {
            if ($metric->avg_response_time_ms === null) {
                $metric->avg_response_time_ms = $responseTime;
            } else {
                // Calculate running average
                $totalResponseTime = ($metric->avg_response_time_ms * ($metric->total_checks - 1)) + $responseTime;
                $metric->avg_response_time_ms = (int) ($totalResponseTime / $metric->total_checks);
            }

            if ($metric->max_response_time_ms === null || $responseTime > $metric->max_response_time_ms) {
                $metric->max_response_time_ms = $responseTime;
            }
        }

        // Update uptime percentage
        $metric->uptime_percentage = ($metric->successful_checks / $metric->total_checks) * 100;

        $metric->save();
    }
}