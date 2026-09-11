<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmBankStatementImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->mapping)) {
            $this->merge(['mapping' => json_decode($this->mapping, true)]);
        }
    }

    public function rules(): array
    {
        return [
            'preview_token' => ['required', 'string'], 'mapping' => ['required', 'array'],
            'mapping.transaction_date' => ['required', 'string'], 'mapping.description' => ['required', 'string'],
            'mapping.amount' => ['required_without_all:mapping.deposit,mapping.withdrawal', 'nullable', 'string'],
            'mapping.deposit' => ['required_without:mapping.amount', 'nullable', 'string'],
            'mapping.withdrawal' => ['required_without:mapping.amount', 'nullable', 'string'],
            'date_format' => ['nullable', 'string', 'max:30'], 'currency_code' => ['required', 'string', 'size:3'],
            'statement_start_date' => ['nullable', 'date'], 'statement_end_date' => ['required', 'date', 'after_or_equal:statement_start_date'],
            'opening_balance' => ['nullable', 'numeric'], 'closing_balance' => ['required', 'numeric'],
        ];
    }
}
