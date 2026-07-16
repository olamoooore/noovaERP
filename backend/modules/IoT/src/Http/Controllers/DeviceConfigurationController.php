<?php

namespace Modules\IoT\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\IoT\Http\Requests\StoreDeviceConfigurationRequest;
use Modules\IoT\Http\Requests\UpdateDeviceConfigurationRequest;
use Modules\IoT\Http\Resources\DeviceConfigurationResource;
use Modules\IoT\Models\DeviceConfiguration;
use Modules\IoT\Services\AgentHttpClient;
use Modules\IoT\Services\DeviceManagementService;

class DeviceConfigurationController extends Controller
{
    public function __construct(
        private DeviceManagementService $deviceService,
        private AgentHttpClient $agentClient
    ) {}

    /**
     * Get all device configurations for an agent.
     *
     * GET /api/v1/iot/agents/{agentId}/device-configs
     */
    public function index(Request $request, string $agentId): JsonResponse
    {
        $query = DeviceConfiguration::byAgent($agentId)->with('statusHistory');

        // Apply filters
        if ($request->has('device_type')) {
            $query->byType($request->device_type);
        }

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }

        if ($request->has('enabled')) {
            $query->where('enabled', $request->boolean('enabled'));
        }

        $configs = $query->latest()->get();

        // Sync status from agent if requested or by default for UI calls
        if ($request->boolean('sync_status', true)) {
            $this->syncDeviceStatusFromAgent($agentId, $configs);
        }

        return response()->json([
            'success' => true,
            'data' => DeviceConfigurationResource::collection($configs),
        ]);
    }

    /**
     * Sync device status from agent.
     * Queries the agent for current device status and updates the database.
     * Checks both registered devices AND discovered devices.
     */
    private function syncDeviceStatusFromAgent(string $agentId, $configs): void
    {
        try {
            // Get registered devices from agent
            // Note: fetchUmpDevices fallback returns 'discovered' key, not 'devices'
            $devicesResult = $this->agentClient->getDevices($agentId);
            $agentDevices = collect($devicesResult['success']
                ? ($devicesResult['devices'] ?? $devicesResult['discovered'] ?? [])
                : []);

            // Also get discovered devices from agent
            $discoveredResult = $this->agentClient->getDiscoveredDevices($agentId);
            $discoveredDevices = collect($discoveredResult['success']
                ? ($discoveredResult['discovered'] ?? $discoveredResult['devices'] ?? [])
                : []);

            // Check if agent is online
            $healthResult = $this->agentClient->checkHealth($agentId);
            $agentOnline = $healthResult['success'];

            foreach ($configs as $config) {
                $connectionInfo = $config->connection_info ?? [];
                $umpDeviceId = $connectionInfo['ump_device_id'] ?? null;
                $vendorId = $connectionInfo['vendorId'] ?? $connectionInfo['vendor_id'] ?? null;
                $productId = $connectionInfo['productId'] ?? $connectionInfo['product_id'] ?? null;

                // Helper to check if a device entry matches this config
                $matchesConfig = function ($device) use ($config, $umpDeviceId, $vendorId, $productId) {
                    // Match by ump_device_id (primary — this is what UMP/agent knows)
                    if ($umpDeviceId && ($device['ump_device_id'] ?? null) === $umpDeviceId) {
                        return true;
                    }
                    // Match by device_id (config's DB UUID)
                    if (($device['device_id'] ?? null) === $config->id) {
                        return true;
                    }
                    // Match by vendor/product ID (legacy, often fails with synthetic hashes)
                    if ($vendorId && $productId) {
                        $deviceVendor = $device['vendor_id'] ?? $device['vendorId'] ?? $device['vendorid'] ?? null;
                        $deviceProduct = $device['product_id'] ?? $device['productId'] ?? $device['productid'] ?? null;
                        if ($deviceVendor && $deviceProduct) {
                            return $this->normalizeHex($vendorId) === $this->normalizeHex($deviceVendor)
                                && $this->normalizeHex($productId) === $this->normalizeHex($deviceProduct);
                        }
                    }

                    return false;
                };

                // First check registered devices
                $matchedDevice = $agentDevices->first($matchesConfig);

                if ($matchedDevice) {
                    // Device found in registered devices - it's active
                    $status = $matchedDevice['status'] ?? 'ready';
                    $config->update([
                        'current_status' => $status,
                        'status_message' => 'Device active on agent',
                        'last_seen_at' => now(),
                    ]);

                    continue;
                }

                // Check discovered devices (device exists but not yet initialized by agent)
                $discoveredMatch = $discoveredDevices->first($matchesConfig);

                if ($discoveredMatch) {
                    // Device found in discovered devices - it's connected but not initialized
                    $config->update([
                        'current_status' => 'discovered',
                        'status_message' => 'Device connected, pending initialization',
                        'last_seen_at' => now(),
                    ]);

                    continue;
                }

                // Device not found anywhere
                if ($agentOnline) {
                    // Only mark as disconnected if device hasn't been active recently
                    // (within last 5 minutes based on last_seen_at)
                    $recentlyActive = $config->last_seen_at && $config->last_seen_at->gt(now()->subMinutes(5));

                    if (! $recentlyActive) {
                        $config->update([
                            'current_status' => 'offline',
                            'status_message' => 'Device not found on agent',
                        ]);
                    }
                }
                // If agent is offline, keep last known status
            }
        } catch (\Exception $e) {
            \Log::warning("Failed to sync device status from agent: {$e->getMessage()}");
        }
    }

    /**
     * Normalize hex value for comparison.
     */
    private function normalizeHex(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        // Remove 0x prefix and convert to lowercase
        $normalized = strtolower(str_replace('0x', '', $value));

        // Remove leading zeros
        return ltrim($normalized, '0') ?: '0';
    }

    /**
     * Create a new device configuration.
     *
     * POST /api/v1/iot/agents/{agentId}/device-configs
     */
    public function store(StoreDeviceConfigurationRequest $request, string $agentId): JsonResponse
    {
        $config = $this->deviceService->createConfiguration(
            $agentId,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'data' => new DeviceConfigurationResource($config),
            'message' => 'Device configuration created successfully',
        ], 201);
    }

    /**
     * Get a specific device configuration.
     *
     * GET /api/v1/iot/agents/{agentId}/device-configs/{deviceId}
     */
    public function show(string $agentId, string $deviceId): JsonResponse
    {
        $config = DeviceConfiguration::byAgent($agentId)
            ->with('statusHistory')
            ->findOrFail($deviceId);

        return response()->json([
            'success' => true,
            'data' => new DeviceConfigurationResource($config),
        ]);
    }

    /**
     * Update a device configuration.
     *
     * PUT /api/v1/iot/agents/{agentId}/device-configs/{deviceId}
     */
    public function update(
        UpdateDeviceConfigurationRequest $request,
        string $agentId,
        string $deviceId
    ): JsonResponse {
        $config = DeviceConfiguration::byAgent($agentId)->findOrFail($deviceId);

        $config = $this->deviceService->updateConfiguration(
            $config,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'data' => new DeviceConfigurationResource($config),
            'message' => 'Device configuration updated successfully',
        ]);
    }

    /**
     * Delete a device configuration.
     *
     * DELETE /api/v1/iot/agents/{agentId}/device-configs/{deviceId}
     */
    public function destroy(string $agentId, string $deviceId): JsonResponse
    {
        $config = DeviceConfiguration::byAgent($agentId)->findOrFail($deviceId);

        $this->deviceService->deleteConfiguration($config);

        return response()->json([
            'success' => true,
            'message' => 'Device configuration deleted successfully',
            'deviceId' => $deviceId,
        ]);
    }

    /**
     * Reload a specific device.
     *
     * POST /api/v1/iot/agents/{agentId}/device-configs/{deviceId}/reload
     */
    public function reload(string $agentId, string $deviceId): JsonResponse
    {
        $config = DeviceConfiguration::byAgent($agentId)->findOrFail($deviceId);

        $this->deviceService->reloadDevice($config);

        return response()->json([
            'success' => true,
            'message' => 'Device reload command sent successfully',
            'deviceId' => $deviceId,
        ]);
    }

    /**
     * Reload all devices for an agent.
     *
     * POST /api/v1/iot/agents/{agentId}/device-configs/reload-all
     */
    public function reloadAll(string $agentId): JsonResponse
    {
        $configs = DeviceConfiguration::byAgent($agentId)->enabled()->get();

        $results = $this->deviceService->reloadAllDevices($configs);

        return response()->json([
            'success' => true,
            'message' => 'Reload command sent to all devices',
            'reloaded' => $results['success'],
            'failed' => $results['failed'],
        ]);
    }
}
