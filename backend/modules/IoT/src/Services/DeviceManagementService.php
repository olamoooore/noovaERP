<?php

namespace Modules\IoT\Services;

use Modules\IoT\Models\DeviceConfiguration;
use Modules\IoT\Events\DeviceConfigUpdated;
use Modules\IoT\Events\DeviceReloadRequested;
use Modules\IoT\Events\DeviceStatusChanged;
use Illuminate\Support\Collection;

class DeviceManagementService
{
    public function __construct(
        private AgentHttpClient $agentClient
    ) {}

    /**
     * Create a new device configuration.
     */
    public function createConfiguration(string $agentId, array $data): DeviceConfiguration
    {
        $config = DeviceConfiguration::create([
            'agent_id' => $agentId,
            'device_type' => $data['device_type'],
            'driver_type' => $data['driver_type'],
            'name' => $data['name'],
            'enabled' => $data['enabled'] ?? true,
            'connection_info' => $data['connection_info'],
            'driver_config' => $data['driver_config'],
            'capabilities' => $data['capabilities'] ?? [],
            'metadata' => $data['metadata'] ?? null,
            'current_status' => 'offline',
        ]);

        // Record initial status
        $config->updateStatus('offline', 'Device configuration created');

        // Broadcast config created event
        broadcast(new DeviceConfigUpdated($config, 'created'))->toOthers();

        return $config;
    }

    /**
     * Update an existing device configuration.
     */
    public function updateConfiguration(DeviceConfiguration $config, array $data): DeviceConfiguration
    {
        $config->update([
            'device_type' => $data['device_type'] ?? $config->device_type,
            'driver_type' => $data['driver_type'] ?? $config->driver_type,
            'name' => $data['name'] ?? $config->name,
            'enabled' => $data['enabled'] ?? $config->enabled,
            'connection_info' => $data['connection_info'] ?? $config->connection_info,
            'driver_config' => $data['driver_config'] ?? $config->driver_config,
            'capabilities' => $data['capabilities'] ?? $config->capabilities,
            'metadata' => $data['metadata'] ?? $config->metadata,
        ]);

        $config = $config->fresh();

        // Broadcast config updated event
        broadcast(new DeviceConfigUpdated($config, 'updated'))->toOthers();

        return $config;
    }

    /**
     * Delete a device configuration.
     */
    public function deleteConfiguration(DeviceConfiguration $config): void
    {
        $agentId = $config->agent_id;
        $deviceId = $config->id;

        $config->delete();

        // Broadcast config deleted event
        broadcast(new DeviceConfigUpdated($config, 'deleted'))->toOthers();
    }

    /**
     * Reload a specific device on the agent.
     */
    public function reloadDevice(DeviceConfiguration $config): void
    {
        // Call agent HTTP API to reload device
        $result = $this->agentClient->reloadDevice($config->agent_id, $config->id);
        
        if ($result['success']) {
            logger()->info("Device reloaded successfully", [
                'device_id' => $config->id,
                'agent_id' => $config->agent_id,
            ]);
        } else {
            logger()->error("Failed to reload device", [
                'device_id' => $config->id,
                'agent_id' => $config->agent_id,
                'error' => $result['error'] ?? 'Unknown error',
            ]);
        }
        
        // Also broadcast reload command via WebSocket for real-time updates
        broadcast(new DeviceReloadRequested($config->agent_id, $config->id))->toOthers();
    }

    /**
     * Reload all devices for an agent.
     */
    public function reloadAllDevices(Collection $configs): array
    {
        $agentId = $configs->first()?->agent_id;

        if (!$agentId) {
            return [
                'success' => 0,
                'failed' => 0,
            ];
        }

        // Call agent HTTP API to reload all devices at once
        $result = $this->agentClient->reloadAllDevices($agentId);
        
        if ($result['success']) {
            logger()->info("All devices reloaded successfully", [
                'agent_id' => $agentId,
                'reloaded' => $result['reloaded'] ?? 0,
            ]);
            
            // Broadcast reload-all command via WebSocket for real-time updates
            broadcast(new DeviceReloadRequested($agentId))->toOthers();
            
            return [
                'success' => $result['reloaded'] ?? 0,
                'failed' => 0,
            ];
        } else {
            logger()->error("Failed to reload all devices", [
                'agent_id' => $agentId,
                'error' => $result['error'] ?? 'Unknown error',
            ]);
            
            return [
                'success' => 0,
                'failed' => $configs->count(),
            ];
        }
    }

    /**
     * Update device status.
     */
    public function updateDeviceStatus(
        DeviceConfiguration $config,
        string $status,
        ?string $message = null,
        ?string $errorCode = null
    ): void {
        $config->updateStatus($status, $message, $errorCode);

        // Broadcast status change to agent and frontend
        broadcast(new DeviceStatusChanged($config, $status, $message, $errorCode))->toOthers();
    }

    /**
     * Notify agent via WebSocket (now implemented via broadcasting).
     */
    private function notifyAgent(string $agentId, string $type, mixed $data): void
    {
        // This method is now deprecated - using Laravel Broadcasting instead
        logger()->info("Broadcasting notification: {$type} for agent {$agentId}", [
            'type' => $type,
            'data' => $data,
        ]);
    }
}
