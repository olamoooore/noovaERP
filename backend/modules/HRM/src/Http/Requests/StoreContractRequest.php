<?php

namespace Modules\HRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'employee_id'          => ['required', 'integer', 'exists:hrm_employees,id'],
            'job_title'            => ['nullable', 'string', 'max:255'],
            'employment_type'      => ['required', 'in:full_time,part_time,contract,intern,commission'],
            'status'               => ['sometimes', 'in:draft,onboarding,active,offboarding,terminated'],
            'base_monthly_salary'  => ['required', 'numeric', 'min:0'],
            'overtime_eligible'    => ['sometimes', 'boolean'],
            'timeshift_id'         => ['nullable', 'integer', 'exists:hrm_timeshifts,id'],
            'fixed_working_days'   => ['sometimes', 'integer', 'min:1', 'max:31'],
            'paid_leaves'          => ['sometimes', 'integer', 'min:0'],
            'start_date'           => ['nullable', 'date'],
            'end_date'             => ['nullable', 'date', 'after_or_equal:start_date'],
            'termination_reason'   => ['nullable', 'string'],
        ];
    }
}
