<?php

namespace Modules\HRM\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'         => $this->id,
            'employee'   => $this->whenLoaded('employee', fn () => [
                'id'              => $this->employee->id,
                'name'            => $this->employee->name,
                'employee_number' => $this->employee->employee_number,
            ]),
            'date'       => $this->date?->toDateString(),
            'leave_type' => $this->leave_type,
            'status'     => $this->status,
            'message'    => $this->message,
            'feedback'   => $this->feedback,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
