<?php

namespace Modules\HRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:hrm_employees,id'],
            'date'        => ['required', 'date'],
            'check_in'    => ['required', 'date_format:H:i'],
            'check_out'   => ['nullable', 'date_format:H:i', 'after:check_in'],
            'notes'       => ['nullable', 'string'],
        ];
    }
}
