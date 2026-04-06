<?php

namespace Modules\HRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePayrollRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'period_start' => ['required', 'date'],
            'period_end'   => ['required', 'date', 'after:period_start'],
            'currency'     => ['nullable', 'string', 'size:3'],
            'notes'        => ['nullable', 'string'],
        ];
    }
}
