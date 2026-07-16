<?php

namespace Modules\HRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceDailySummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'date' => $this->date?->toDateString(),
            'first_check_in' => $this->first_check_in,
            'last_check_out' => $this->last_check_out,
            'worked_minutes' => $this->worked_minutes,
            'worked_hours' => $this->worked_minutes !== null
                ? round($this->worked_minutes / 60, 2)
                : null,
            'status' => $this->status,
            'has_exception' => (bool) $this->has_exception,
            'exception_type' => $this->exception_type ?? 'none',
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'name' => $this->employee->name,
                'employee_number' => $this->employee->employee_number,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
