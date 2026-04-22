<?php

namespace Modules\FinancialClose\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateEscalationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason'       => ['required', 'string', 'max:1000'],
            'escalated_to' => ['nullable', 'string', 'uuid'],
        ];
    }
}
