<?php

use App\Http\Controllers\Api\UserAgentController;
use Illuminate\Support\Facades\Route;
use Modules\IoT\Http\Controllers\AgentConfigController;
use Modules\IoT\Http\Controllers\AgentHealthController;
use Modules\IoT\Http\Controllers\DeviceConfigurationController;
use Modules\IoT\Http\Controllers\DeviceStatusController;
use Modules\IoT\Http\Controllers\DeviceTemplateController;
use Modules\IoT\Http\Controllers\DiscoveredDeviceController;
use Modules\IoT\Http\Controllers\IoTAgentController;
use Modules\IoT\Http\Controllers\IoTDeviceController;
use Modules\IoT\Http\Controllers\IoTDeviceTemplateController;
use Modules\IoT\Http\Controllers\IoTMonitoringController;
use Modules\IoT\Http\Controllers\IoTPrinterController;
use Modules\IoT\Http\Controllers\PrinterJobController;
use Modules\IoT\Http\Controllers\ReceiptPrinterController;
use Modules\IoT\Http\Controllers\ScannerJobController;

/*
|--------------------------------------------------------------------------
| IoT Module API Routes
|--------------------------------------------------------------------------
|
| These routes are tenant-scoped and require the 'entitlement:iot' middleware.
| All routes are prefixed with /api/v1/iot
|
*/

Route::middleware(['auth:sanctum', 'entitlement:iot'])->prefix('iot')->group(function () {

    // Device Configuration Management (Agent-specific)
    // These routes accept both user tokens (Sanctum) and agent tokens (custom)
    Route::prefix('agents/{agentId}')
        ->withoutMiddleware([
            'auth:sanctum',
            'entitlement:iot',
        ])
        ->middleware([
            'auth.agent_or_user',
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
            'entitlement:iot',
        ])->group(function () {
            // Agent heartbeat (agent reports it's alive)
            Route::post('heartbeat', [AgentHealthController::class, 'heartbeat']);

            // Agent health and status
            Route::get('health', [AgentHealthController::class, 'check']);
            Route::get('devices', [AgentHealthController::class, 'getDevices']);
            Route::get('discovered', [AgentHealthController::class, 'getDiscovered']);

            // Device configurations
            Route::get('device-configs', [DeviceConfigurationController::class, 'index']);
            Route::post('device-configs', [DeviceConfigurationController::class, 'store']);
            Route::get('device-configs/{deviceId}', [DeviceConfigurationController::class, 'show']);
            Route::put('device-configs/{deviceId}', [DeviceConfigurationController::class, 'update']);
            Route::delete('device-configs/{deviceId}', [DeviceConfigurationController::class, 'destroy']);

            // Device reload operations
            Route::post('device-configs/{deviceId}/reload', [DeviceConfigurationController::class, 'reload']);
            Route::post('device-configs/reload-all', [DeviceConfigurationController::class, 'reloadAll']);

            // Device discovery reporting (agent reports discovered devices)
            Route::post('discovered-devices', [DiscoveredDeviceController::class, 'report']);

            // Device status updates (agent reports status changes)
            Route::post('device-status', [DeviceStatusController::class, 'update']);

            // Scanner job operations
            Route::prefix('scanner')->group(function () {
                Route::post('start-listening', [ScannerJobController::class, 'startListening']);
                Route::post('stop-listening', [ScannerJobController::class, 'stopListening']);
                Route::post('scan-result', [ScannerJobController::class, 'reportScanResult']);
                Route::get('history', [ScannerJobController::class, 'getHistory']);
            });

            // Printer job operations
            Route::prefix('printer')->group(function () {
                Route::post('print-label', [PrinterJobController::class, 'printLabel']);
                Route::post('job-result', [PrinterJobController::class, 'reportJobResult']);
                Route::get('status', [PrinterJobController::class, 'getStatus']);
                Route::get('history', [PrinterJobController::class, 'getHistory']);
                Route::post('test-receipt', [ReceiptPrinterController::class, 'testReceipt']);
            });
        });

    // Discovered Devices (Tenant-wide view)
    Route::prefix('discovered-devices')->group(function () {
        Route::get('/', [DiscoveredDeviceController::class, 'index']);
        Route::get('{deviceId}', [DiscoveredDeviceController::class, 'show']);
        Route::patch('{deviceId}/ignore', [DiscoveredDeviceController::class, 'ignore']);
        Route::post('scan', [DiscoveredDeviceController::class, 'scan']);
    });

    // Device Templates (Read-only for agents/admins)
    Route::prefix('device-templates')->group(function () {
        Route::get('/', [DeviceTemplateController::class, 'index']);
        Route::get('{templateId}', [DeviceTemplateController::class, 'show']);
    });

    // Agent Configuration
    Route::prefix('agent-config')->group(function () {
        Route::get('/', [AgentConfigController::class, 'show']);
        Route::put('/', [AgentConfigController::class, 'update']);
        Route::post('test', [AgentConfigController::class, 'test']);
    });

    // Receipt Printer (Tenant-wide, not agent-specific in URL)
    Route::prefix('print-jobs')->group(function () {
        Route::post('receipt', [ReceiptPrinterController::class, 'printReceipt']);
    });

    // User Agent Linking
    Route::post('/user/link-agent', [UserAgentController::class, 'linkAgent']);
    Route::post('/user/unlink-agent', [UserAgentController::class, 'unlinkAgent']);
    Route::get('/user/linked-agent', [UserAgentController::class, 'getLinkedAgent']);

    // Device Management
    Route::get('/devices', [IoTDeviceController::class, 'index']);
    Route::post('/devices', [IoTDeviceController::class, 'store']);
    Route::get('/devices/{device}', [IoTDeviceController::class, 'show']);
    Route::put('/devices/{device}', [IoTDeviceController::class, 'update']);
    Route::delete('/devices/{device}', [IoTDeviceController::class, 'destroy']);
    Route::post('/devices/{device}/test', [IoTDeviceController::class, 'test']);

    // Device Logs
    Route::get('devices/{device}/logs', [IoTDeviceController::class, 'logs']);
    Route::post('devices/{device}/save-as-template', [IoTDeviceController::class, 'saveAsTemplate']);

    // Device Templates (Advanced)
    Route::get('templates', [IoTDeviceTemplateController::class, 'index']);
    Route::post('templates', [IoTDeviceTemplateController::class, 'store']);
    Route::get('templates/categories', [IoTDeviceTemplateController::class, 'categories']);
    Route::get('templates/{template}', [IoTDeviceTemplateController::class, 'show']);
    Route::put('templates/{template}', [IoTDeviceTemplateController::class, 'update']);
    Route::delete('templates/{template}', [IoTDeviceTemplateController::class, 'destroy']);
    Route::post('templates/{template}/create-device', [IoTDeviceTemplateController::class, 'createDevice']);

    // Health Monitoring
    Route::get('monitoring/dashboard', [IoTMonitoringController::class, 'dashboard']);
    Route::get('monitoring/system-health', [IoTMonitoringController::class, 'systemHealth']);
    Route::get('monitoring/performance-trends', [IoTMonitoringController::class, 'performanceTrends']);
    Route::get('monitoring/recent-alerts', [IoTMonitoringController::class, 'recentAlerts']);
    Route::post('monitoring/check-all', [IoTMonitoringController::class, 'checkAllDevices']);

    // Device Health Monitoring
    Route::get('devices/{device}/health', [IoTMonitoringController::class, 'deviceHealth']);
    Route::get('devices/{device}/health-logs', [IoTMonitoringController::class, 'deviceHealthLogs']);
    Route::get('devices/{device}/metrics', [IoTMonitoringController::class, 'deviceMetrics']);
    Route::post('devices/{device}/check-health', [IoTMonitoringController::class, 'checkDevice']);

    // Printer Routes
    Route::post('printers/{device}/print-label', [IoTPrinterController::class, 'printLabel']);
    Route::get('printers/{device}/status', [IoTPrinterController::class, 'getStatus']);
    Route::post('printers/{device}/test', [IoTPrinterController::class, 'testConnection']);

    // Agent Health Check (requires auth)
    Route::get('agent/devices/{device}/health', [IoTAgentController::class, 'health']);
    Route::get('/agent/verify/{agentId}', [IoTAgentController::class, 'verifyAgent']);
});
