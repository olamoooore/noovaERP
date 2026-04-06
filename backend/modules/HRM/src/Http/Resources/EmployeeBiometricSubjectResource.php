<?php

namespace Modules\HRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeBiometricSubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'employee_id'  => $this->employee_id,
            'subject_code' => $this->subject_code,
            'type'         => $this->type,
            'status'       => $this->status,
            'employee'     => $this->whenLoaded('employee', fn () => [
                'id'              => $this->employee->id,
                'name'            => $this->employee->name,
                'employee_number' => $this->employee->employee_number,
            ]),
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
