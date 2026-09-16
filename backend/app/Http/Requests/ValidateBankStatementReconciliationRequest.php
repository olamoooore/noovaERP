<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ValidateBankStatementReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'],
            'import_ids' => ['required', 'array', 'min:1'],
            'import_ids.*' => ['required', 'uuid', 'distinct', 'exists:bank_statement_imports,id'],
        ];
    }
}
