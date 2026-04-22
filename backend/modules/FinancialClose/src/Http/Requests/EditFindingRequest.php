<?php

namespace Modules\FinancialClose\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EditFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'edited_output' => ['required', 'array'],
        ];
    }
}
