<?php

namespace Modules\IoT\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceConfigurationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agentId' => $this->agent_id,
            'connectionType' => $this->connection_type,
            'connection_type' => $this->connection_type,
            'deviceType' => $this->device_type,
            'driverType' => $this->driver_type,
            'name' => $this->name,
            'enabled' => $this->enabled,
            'connectionInfo' => $this->transformConnectionInfo($this->connection_info),
            'driverConfig' => $this->driver_config,
            'capabilities' => $this->capabilities ?? [],
            'metadata' => $this->metadata,
            'currentStatus' => $this->current_status,
            'statusMessage' => $this->status_message,
            'lastSeenAt' => $this->last_seen_at?->toISOString(),
            'isOnline' => $this->isOnline() || in_array($this->current_status, ['ready', 'discovered', 'initializing', 'busy']),
            'createdAt' => $this->created_at->toISOString(),
            'updatedAt' => $this->updated_at->toISOString(),
            'statusHistory' => DeviceStatusHistoryResource::collection($this->whenLoaded('statusHistory')),
        ];
    }

    /**
     * Transform connection_info keys for frontend/agent compatibility.
     * Preserves new multi-protocol keys while maintaining backward compatibility with legacy USB fields.
     */
    private function transformConnectionInfo(?array $connectionInfo): array
    {
        if (!$connectionInfo) {
            return [];
        }

        // Start with the raw stored data (includes new keys: type, agent_id, device_path, etc.)
        $result = $connectionInfo;

        // Add legacy camelCase aliases for backward compatibility
        $result['vendorId'] = $connectionInfo['vendor_id'] ?? $connectionInfo['vendorId'] ?? null;
        $result['productId'] = $connectionInfo['product_id'] ?? $connectionInfo['productId'] ?? null;
        $result['port'] = $connectionInfo['port'] ?? $connectionInfo['device_path'] ?? null;
        $result['printerName'] = $connectionInfo['printer_name'] ?? $connectionInfo['printerName'] ?? null;

        return $result;
    }
}
