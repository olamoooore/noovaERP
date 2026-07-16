<?php

namespace Modules\HRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:hrm_employees,email'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'manager_id' => ['nullable', 'integer', 'exists:hrm_employees,id'],
            'status' => ['sometimes', 'in:active,on_leave,probation,terminated'],
        ];
    }
}
