<?php

namespace Modules\IoT\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'deviceType' => $this->device_type,
            'driverType' => $this->driver_type,
            'vendorId' => $this->vendor_id,
            'productId' => $this->product_id,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'defaultConfig' => $this->default_config,
            'capabilities' => $this->capabilities ?? [],
            'description' => $this->description,
            'icon' => $this->icon,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at->toISOString(),
            'updatedAt' => $this->updated_at->toISOString(),
        ];
    }
}
