<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviewBankStatementImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:25600',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (! in_array($extension, ['csv', 'xlsx'], true)) {
                        $fail('The statement file must have a .csv or .xlsx extension.');
                    }
                },
            ],
            'bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'],
            'worksheet' => ['nullable', 'string', 'max:255'], 'header_row' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
