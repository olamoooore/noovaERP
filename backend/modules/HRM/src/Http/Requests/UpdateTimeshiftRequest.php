<?php

namespace Modules\HRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTimeshiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name'               => ['sometimes', 'string', 'max:255'],
            'is_default'         => ['sometimes', 'boolean'],
            'schedule'           => ['sometimes', 'array', 'min:1'],
            'schedule.*.day'     => ['required_with:schedule', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'schedule.*.enabled' => ['required_with:schedule', 'boolean'],
            'schedule.*.start'   => ['nullable', 'date_format:H:i'],
            'schedule.*.end'     => ['nullable', 'date_format:H:i'],
        ];
    }
}
