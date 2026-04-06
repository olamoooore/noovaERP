<?php

namespace Modules\HRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeBiometricSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $employeeId = $this->route('employee')?->id;

        return [
            'subject_code' => ['required', 'string', 'max:255'],
            'type'         => [
                'required',
                'in:palm_vein,face,fingerprint,rfid',
                // One subject per modality per employee (ignore soft-deleted rows)
                "unique:hrm_employee_biometric_subjects,type,NULL,id,employee_id,{$employeeId},deleted_at,NULL",
            ],
            'status'       => ['nullable', 'in:active,inactive,pending'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.unique' => 'This employee already has a biometric subject registered for that modality.',
        ];
    }
}
