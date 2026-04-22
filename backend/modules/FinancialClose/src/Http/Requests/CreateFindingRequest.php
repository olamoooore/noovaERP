<?php

namespace Modules\FinancialClose\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_id'    => ['required', 'string'],
            'account_code' => ['required', 'string', 'max:100'],
            'finding_type' => ['required', 'string', 'max:100'],
            'description'  => ['required', 'string', 'max:4000'],
            'risk_status'  => ['required', 'string', Rule::in(['low', 'medium', 'high', 'critical'])],
            'confidence'   => ['required', 'numeric', 'min:0', 'max:1'],
            'ai_output'    => ['required', 'array'],
        ];
    }
}
