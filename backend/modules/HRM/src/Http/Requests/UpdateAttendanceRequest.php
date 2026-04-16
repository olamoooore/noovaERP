<?php

namespace Modules\HRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'date'      => ['sometimes', 'date'],
            'check_in'  => ['sometimes', 'date_format:H:i'],
            'check_out' => ['sometimes', 'nullable', 'date_format:H:i', 'after:check_in'],
            'notes'     => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
