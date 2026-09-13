<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateBankStatementMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'bank_statement_transaction_id' => ['required', 'uuid', 'exists:bank_statement_transactions,id'],
            'journal_entry_line_id' => ['required', 'integer', 'exists:journal_entry_lines,id'],
            'matched_amount' => ['required', 'numeric', 'not_in:0'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'review_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
