<?php

namespace Modules\IoT\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\IoT\Models\DiscoveredDevice;
use Modules\IoT\Http\Requests\ReportDiscoveredDevicesRequest;
use Modules\IoT\Http\Resources\DiscoveredDeviceResource;
use Modules\IoT\Services\DeviceDiscoveryService;
use Modules\IoT\Services\DeviceTemplateMatchingService;

class DiscoveredDeviceController extends Controller
{
    public function __construct(
        private DeviceDiscoveryService $discoveryService,
        private DeviceTemplateMatchingService $templateMatcher,
        private \Modules\IoT\Services\AgentHttpClient $agentClient
    ) {}

    /**
     * Agent reports discovered devices.
     * 
     * POST /api/v1/iot/agents/{agentId}/discovered-devices
     */
    public function report(ReportDiscoveredDevicesRequest $request, string $agentId): JsonResponse
    {
        $devices = $request->input('devices');
        $discovered = [];

        foreach ($devices as $deviceData) {
            // Store or update discovered device
            $device = $this->discoveryService->storeDiscoveredDevice($agentId, $deviceData);

            // Match with templates
            $suggestedTemplate = $this->templateMatcher->findMatch($device);
            
            // Broadcast with template suggestion if this is a new discovery
            if ($device->wasRecentlyCreated && $suggestedTemplate) {
                broadcast(new \Modules\IoT\Events\DeviceDiscovered($device, $suggestedTemplate))->toOthers();
            }

            $discovered[] = [
                'id' => $device->id,
                'vendorId' => $device->vendor_id,
                'productId' => $device->product_id,
                'manufacturer' => $device->manufacturer,
                'model' => $device->model,
                'serialNumber' => $device->serial_number,
                'port' => $device->port,
                'connectionType' => $device->connection_type,
                'status' => $device->status,
                'suggestedTemplate' => $suggestedTemplate ? [
                    'id' => $suggestedTemplate->id,
                    'name' => $suggestedTemplate->name,
                    'deviceType' => $suggestedTemplate->device_type,
                    'driverType' => $suggestedTemplate->driver_type,
                    'defaultConfig' => $suggestedTemplate->default_config,
                    'capabilities' => $suggestedTemplate->capabilities,
                ] : null,
            ];
        }

        return response()->json([
            'success' => true,
            'discovered' => $discovered,
        ]);
    }

    /**
     * Get all discovered devices.
     * 
     * GET /api/v1/iot/discovered-devices
     */
    public function index(Request $request): JsonResponse
    {
        $query = DiscoveredDevice::query();

        // Apply filters
        if ($request->has('agent_id')) {
            $query->byAgent($request->agent_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        } else {
            // By default, only show discovered devices (not offline/stale)
            $query->where('status', 'discovered');
        }

        if ($request->has('connection_type')) {
            $query->byConnectionType($request->connection_type);
        }

        $devices = $query->with('configuredDevice')->latest('last_seen_at')->get();

        return response()->json([
            'success' => true,
            'data' => DiscoveredDeviceResource::collection($devices),
        ]);
    }

    /**
     * Get a specific discovered device.
     * 
     * GET /api/v1/iot/discovered-devices/{deviceId}
     */
    public function show(string $deviceId): JsonResponse
    {
        $device = DiscoveredDevice::with('configuredDevice')->findOrFail($deviceId);

        return response()->json([
            'success' => true,
            'data' => new DiscoveredDeviceResource($device),
        ]);
    }

    /**
     * Mark a discovered device as ignored.
     * 
     * PATCH /api/v1/iot/discovered-devices/{deviceId}/ignore
     */
    public function ignore(string $deviceId): JsonResponse
    {
        $device = DiscoveredDevice::findOrFail($deviceId);
        $device->markAsIgnored();

        return response()->json([
            'success' => true,
            'message' => 'Device marked as ignored',
        ]);
    }

    /**
     * Trigger device discovery scan on all agents.
     * 
     * POST /api/v1/iot/discovered-devices/scan
     */
    public function scan(Request $request): JsonResponse
    {
        // Get agent ID from request or use default
        $agentId = $request->input('agent_id');
        
        if (!$agentId) {
            return response()->json([
                'success' => false,
                'message' => 'Agent ID is required',
            ], 400);
        }

        try {
            // Trigger discovery on the specified agent
            $result = $this->agentClient->triggerDiscovery($agentId);
            
            \Log::info('Agent discovery result', [
                'agent_id' => $agentId,
                'result' => $result,
            ]);
            
            if ($result['success']) {
                // Sync devices with agent's current list
                // This will mark devices not in the list as offline
                $syncResult = $this->discoveryService->syncDevicesWithAgent(
                    $agentId,
                    $result['discovered'] ?? []
                );
                
                return response()->json([
                    'success' => true,
                    'message' => 'Discovery scan completed successfully',
                    'discovered' => $result['discovered'] ?? [],
                    'stats' => [
                        'updated' => $syncResult['updated'],
                        'marked_offline' => $syncResult['marked_offline'],
                        'configured' => $syncResult['configured'] ?? 0,
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to trigger discovery scan',
                    'error' => $result['error'] ?? 'Unknown error',
                ], 500);
            }
        } catch (\Exception $e) {
            \Log::error('Discovery scan failed', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to trigger discovery scan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
