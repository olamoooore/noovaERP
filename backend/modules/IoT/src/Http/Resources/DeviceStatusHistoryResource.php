<?php

namespace Modules\IoT\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceStatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'deviceId' => $this->device_id,
            'agentId' => $this->agent_id,
            'status' => $this->status,
            'message' => $this->message,
            'errorCode' => $this->error_code,
            'recordedAt' => $this->recorded_at->toISOString(),
        ];
    }
}
