<?php

namespace Modules\IoT\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscoveredDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agentId' => $this->agent_id,
            'vendorId' => $this->vendor_id,
            'productId' => $this->product_id,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'serialNumber' => $this->serial_number,
            'port' => $this->port,
            'connectionType' => $this->connection_type,
            'status' => $this->status,
            'uniqueIdentifier' => $this->getUniqueIdentifier(),
            'configuredDevice' => new DeviceConfigurationResource($this->whenLoaded('configuredDevice')),
            'discoveredAt' => $this->discovered_at->toISOString(),
            'lastSeenAt' => $this->last_seen_at?->toISOString(),
        ];
    }
}
