<?php

namespace Modules\FinancialClose\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LinkDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', 'in:bill,invoice,payment,journal_entry,credit_note,debit_note'],
            'document_id'   => ['required', 'integer'],
            'title'         => ['nullable', 'string', 'max:500'],
        ];
    }
}