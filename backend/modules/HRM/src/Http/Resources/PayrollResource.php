<?php

namespace Modules\HRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'employee'            => $this->whenLoaded('employee', fn () => [
                'id'              => $this->employee->id,
                'name'            => optional($this->employee->user)->name ?? "Employee #{$this->employee->id}",
                'employee_number' => $this->employee->employee_number,
            ]),
            'base_monthly_salary' => $this->base_monthly_salary,
            'total_deductions'    => $this->items->sum('amount'),
            'net_pay'             => $this->net_pay,
            'status'              => $this->status,
            'items'               => PayrollItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
