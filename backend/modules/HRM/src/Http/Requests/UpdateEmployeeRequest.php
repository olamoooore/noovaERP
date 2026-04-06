<?php

namespace Modules\HRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $employee = $this->route('employee');
        $id = $employee instanceof \Modules\HRM\Models\Employee ? $employee->id : $employee;

        return [
            'name'           => ['sometimes', 'string', 'max:255'],
            'email'          => ['sometimes', 'email', 'max:255', "unique:hrm_employees,email,{$id}"],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'user_id'        => ['nullable', 'integer', 'exists:users,id'],
            'department_id'  => ['nullable', 'integer', 'exists:departments,id'],
            'location_id'    => ['nullable', 'integer', 'exists:locations,id'],
            'manager_id'     => ['nullable', 'integer', 'exists:hrm_employees,id', "not_in:{$id}"],
            'status'         => ['sometimes', 'in:active,on_leave,probation,terminated'],
        ];
    }
}
