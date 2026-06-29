<?php

namespace Modules\IoT\Services;

use Modules\IoT\Models\DiscoveredDevice;
use Modules\IoT\Events\DeviceDiscovered;
use Carbon\Carbon;

class DeviceDiscoveryService
{
    /**
     * Store or update a discovered device.
     */
    public function storeDiscoveredDevice(string $agentId, array $deviceData): DiscoveredDevice
    {
        // Normalize keys from camelCase to snake_case (agent sends camelCase)
        $normalizedData = [
            'vendor_id' => $deviceData['vendor_id'] ?? $deviceData['vendorId'] ?? null,
            'product_id' => $deviceData['product_id'] ?? $deviceData['productId'] ?? null,
            'manufacturer' => $deviceData['manufacturer'] ?? null,
            'model' => $deviceData['model'] ?? $deviceData['product'] ?? null,
            'serial_number' => $deviceData['serial_number'] ?? $deviceData['serialNumber'] ?? null,
            'port' => $deviceData['port'] ?? null,
            'connection_type' => $deviceData['connection_type'] ?? $deviceData['connectionType'] ?? 'hid',
        ];

        // Skip devices without vendor/product IDs
        if (!$normalizedData['vendor_id'] || !$normalizedData['product_id']) {
            throw new \InvalidArgumentException('Device must have vendor_id and product_id');
        }

        $uniqueIdentifier = $this->generateUniqueIdentifier($normalizedData);

        // Check if device already exists
        $device = DiscoveredDevice::byAgent($agentId)
            ->where('vendor_id', $normalizedData['vendor_id'])
            ->where('product_id', $normalizedData['product_id'])
            ->where(function ($query) use ($normalizedData) {
                if (isset($normalizedData['serial_number'])) {
                    $query->where('serial_number', $normalizedData['serial_number']);
                } else {
                    $query->where('port', $normalizedData['port'] ?? null);
                }
            })
            ->first();

        if ($device) {
            // Update existing device
            $device->update([
                'manufacturer' => $normalizedData['manufacturer'] ?? $device->manufacturer,
                'model' => $normalizedData['model'] ?? $device->model,
                'port' => $normalizedData['port'] ?? $device->port,
                'connection_type' => $normalizedData['connection_type'],
                'last_seen_at' => now(),
                'status' => 'discovered', // Mark as discovered again
            ]);
        } else {
            // Create new discovered device
            $device = DiscoveredDevice::create([
                'agent_id' => $agentId,
                'vendor_id' => $normalizedData['vendor_id'],
                'product_id' => $normalizedData['product_id'],
                'manufacturer' => $normalizedData['manufacturer'],
                'model' => $normalizedData['model'],
                'serial_number' => $normalizedData['serial_number'],
                'port' => $normalizedData['port'],
                'connection_type' => $normalizedData['connection_type'],
                'status' => 'discovered',
                'discovered_at' => now(),
                'last_seen_at' => now(),
            ]);
            
            // Broadcast new device discovery (only for new devices, not updates)
            // Template matching will be done by the controller
            broadcast(new DeviceDiscovered($device))->toOthers();
        }

        return $device;
    }

    /**
     * Mark devices as offline if not seen recently.
     */
    public function markStaleDevicesOffline(string $agentId, int $minutesThreshold = 10): int
    {
        $threshold = Carbon::now()->subMinutes($minutesThreshold);

        return DiscoveredDevice::byAgent($agentId)
            ->where('status', 'discovered')
            ->where('last_seen_at', '<', $threshold)
            ->update(['status' => 'offline']);
    }

    /**
     * Generate a unique identifier for a device.
     */
    private function generateUniqueIdentifier(array $deviceData): string
    {
        return sprintf(
            '%s-%s-%s',
            $deviceData['vendor_id'] ?? 'unknown',
            $deviceData['product_id'] ?? 'unknown',
            $deviceData['serial_number'] ?? $deviceData['port'] ?? 'unknown'
        );
    }

    /**
     * Bulk store discovered devices.
     */
    public function bulkStoreDiscoveredDevices(string $agentId, array $devices): array
    {
        $stored = [];

        foreach ($devices as $deviceData) {
            try {
                $device = $this->storeDiscoveredDevice($agentId, $deviceData);
                $stored[] = $device;
            } catch (\Exception $e) {
                logger()->error("Failed to store discovered device: {$e->getMessage()}", [
                    'agent_id' => $agentId,
                    'device_data' => $deviceData,
                ]);
            }
        }

        return $stored;
    }

    /**
     * Sync discovered devices with agent's current device list.
     * Marks devices not in the list as offline.
     * Also auto-creates DeviceConfiguration records for printer devices.
     * 
     * @param string $agentId
     * @param array $currentDevices Array of device data from agent
     * @return array ['updated' => int, 'marked_offline' => int, 'configured' => int]
     */
    public function syncDevicesWithAgent(string $agentId, array $currentDevices): array
    {
        $updated = 0;
        $deviceIds = [];
        $configured = 0;

        // Update/create devices from agent's current list
        foreach ($currentDevices as $deviceData) {
            $device = $this->storeDiscoveredDevice($agentId, $deviceData);
            $deviceIds[] = $device->id;
            $updated++;

            // Auto-create DeviceConfiguration for printer devices
            $umpDeviceId = $deviceData['ump_device_id'] ?? $deviceData['serialNumber'] ?? $deviceData['serial_number'] ?? null;
            $model = $deviceData['model'] ?? $device->model ?? 'Unknown Printer';
            $connType = $deviceData['connectionType'] ?? $deviceData['connection_type'] ?? $device->connection_type ?? 'usb';
            $vendorId = $deviceData['vendorId'] ?? $deviceData['vendor_id'] ?? $device->vendor_id ?? null;
            $productId = $deviceData['productId'] ?? $deviceData['product_id'] ?? $device->product_id ?? null;

            if ($umpDeviceId) {
                $existingConfig = \Modules\IoT\Models\DeviceConfiguration::where('agent_id', $agentId)
                    ->where(function ($q) use ($umpDeviceId, $vendorId, $productId) {
                        $q->where('connection_info->ump_device_id', $umpDeviceId);
                        if ($vendorId && $productId) {
                            $q->orWhere('connection_info->vendorId', $vendorId)
                              ->orWhere('connection_info->productId', $productId);
                        }
                    })
                    ->exists();

                if (!$existingConfig) {
                    \Modules\IoT\Models\DeviceConfiguration::create([
                        'agent_id' => $agentId,
                        'name' => $model,
                        'device_type' => 'printer',
                        'driver_type' => 'tspl',
                        'connection_type' => 'agent',
                        'enabled' => true,
                        'connection_info' => [
                            'ump_device_id' => $umpDeviceId,
                            'vendorId' => $vendorId,
                            'productId' => $productId,
                            'serialNumber' => $deviceData['serialNumber'] ?? $deviceData['serial_number'] ?? null,
                        ],
                        'current_status' => 'online',
                        'last_seen_at' => now(),
                    ]);
                    $configured++;
                }
            }
        }

        // Mark devices not in the current list as offline
        $markedOffline = DiscoveredDevice::where('agent_id', $agentId)
            ->where('status', 'discovered')
            ->whereNotIn('id', $deviceIds)
            ->update([
                'status' => 'offline',
            ]);

        return [
            'updated' => $updated,
            'marked_offline' => $markedOffline,
            'configured' => $configured,
        ];
    }
}
