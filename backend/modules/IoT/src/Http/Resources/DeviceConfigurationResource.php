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
            'isOnline' => $this->isOnline() || in_array($this->current_status, ['ready', 'discovered', 'initializing']),
            'createdAt' => $this->created_at->toISOString(),
            'updatedAt' => $this->updated_at->toISOString(),
            'statusHistory' => DeviceStatusHistoryResource::collection($this->whenLoaded('statusHistory')),
        ];
    }

    /**
     * Transform connection_info keys to camelCase for frontend/agent compatibility
     */
    private function transformConnectionInfo(?array $connectionInfo): array
    {
        if (!$connectionInfo) {
            return [];
        }

        return [
            'vendorId' => $connectionInfo['vendor_id'] ?? $connectionInfo['vendorId'] ?? null,
            'productId' => $connectionInfo['product_id'] ?? $connectionInfo['productId'] ?? null,
            'port' => $connectionInfo['port'] ?? null,
            'printerName' => $connectionInfo['printer_name'] ?? $connectionInfo['printerName'] ?? null,
        ];
    }
}
