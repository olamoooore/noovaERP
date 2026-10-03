<?php

namespace Modules\HRM\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ContractResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'name' => $this->employee->name,
                'employee_number' => $this->employee->employee_number,
            ]),
            'job_title' => $this->job_title,
            'employment_type' => $this->employment_type,
            'status' => $this->status,
            // #787 (Y1): salary is payroll data — hidden unless the caller
            // holds hrm.payroll.view (same resolution as the route guard).
            'base_monthly_salary' => $this->when(
                \App\Http\Middleware\RequirePermission::allows(request(), 'hrm.payroll.view'),
                fn () => (float) $this->base_monthly_salary,
            ),
            'overtime_eligible' => (bool) $this->overtime_eligible,
            'timeshift' => $this->whenLoaded('timeshift', fn () => $this->timeshift ? [
                'id' => $this->timeshift->id,
                'name' => $this->timeshift->name,
            ] : null),
            'fixed_working_days' => $this->fixed_working_days,
            'paid_leaves' => $this->paid_leaves,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'termination_reason' => $this->termination_reason,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
