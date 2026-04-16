<?php

namespace Modules\HRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'employee_id'     => $this->employee_id,
            'subject_id'      => $this->subject_id,
            'device_id'       => $this->device_id,
            'location_id'     => $this->location_id,
            'event_type'      => $this->event_type,
            'captured_at'     => $this->captured_at?->toIso8601String(),
            'source_event_id' => $this->source_event_id,
            'status'          => $this->status,
            'meta'            => $this->meta,
            'employee'        => $this->whenLoaded('employee', fn () => [
                'id'              => $this->employee->id,
                'name'            => $this->employee->name,
                'employee_number' => $this->employee->employee_number,
            ]),
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
