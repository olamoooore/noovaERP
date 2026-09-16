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

        $mapping = $this->input('mapping');
        if (is_array($mapping)) {
            // Accept saved mappings created before the Debit/Credit terminology change.
            $mapping['debit'] ??= $mapping['withdrawal'] ?? null;
            $mapping['credit'] ??= $mapping['deposit'] ?? null;
            $this->merge(['mapping' => array_filter($mapping, fn ($value) => $value !== null)]);
        }
    }

    public function rules(): array
    {
        return [
            'preview_token' => ['required', 'string'], 'mapping' => ['required', 'array'],
            'mapping.transaction_date' => ['required', 'string'], 'mapping.description' => ['required', 'string'],
            'mapping.amount' => ['required_without_all:mapping.debit,mapping.credit', 'nullable', 'string'],
            'mapping.debit' => ['required_without:mapping.amount', 'nullable', 'string'],
            'mapping.credit' => ['required_without:mapping.amount', 'nullable', 'string'],
            'date_format' => ['nullable', 'string', 'max:30'], 'currency_code' => ['required', 'string', 'size:3'],
            'statement_start_date' => ['nullable', 'date'], 'statement_end_date' => ['required', 'date', 'after_or_equal:statement_start_date'],
            'opening_balance' => ['nullable', 'numeric'], 'closing_balance' => ['required', 'numeric'],
        ];
    }
}
