<?php

namespace Modules\HRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTimeshiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name'               => ['required', 'string', 'max:255'],
            'is_default'         => ['sometimes', 'boolean'],
            'schedule'           => ['required', 'array', 'min:1'],
            'schedule.*.day'     => ['required', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'schedule.*.enabled' => ['required', 'boolean'],
            'schedule.*.start'   => ['nullable', 'date_format:H:i'],
            'schedule.*.end'     => ['nullable', 'date_format:H:i', 'after:schedule.*.start'],
        ];
    }
}
