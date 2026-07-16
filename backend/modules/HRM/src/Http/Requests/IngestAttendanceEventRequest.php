<?php

namespace Modules\HRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IngestAttendanceEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:hrm_employees,id'],
            'event_type' => ['required', 'string', 'in:check_in,check_out'],
            'captured_at' => ['required', 'date'],
            'source_event_id' => ['required', 'string', 'max:255'],
            'subject_id' => ['nullable', 'integer', 'exists:hrm_employee_biometric_subjects,id'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
