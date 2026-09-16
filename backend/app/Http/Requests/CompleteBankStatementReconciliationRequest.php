<?php

namespace App\Http\Requests;

class CompleteBankStatementReconciliationRequest extends ValidateBankStatementReconciliationRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'idempotency_key' => ['required', 'string', 'max:100']];
    }
}
