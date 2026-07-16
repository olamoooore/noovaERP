<?php

namespace Modules\EventManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReissueTicketRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'original_ticket_code' => [
                'required',
                'string',
                'regex:/^TKT-[A-Z0-9]{8}-[A-Z0-9]{4}$/',
                'max:20',
            ],
            'reason' => [
                'required',
                'string',
                'min:10',
                'max:500',
                'in:lost,damaged,stolen,system_error,customer_request,other',
            ],
            'reason_details' => [
                'sometimes',
                'string',
                'max:1000',
            ],
            'notify_customer' => [
                'sometimes',
                'boolean',
            ],
            'revoke_original' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'original_ticket_code.required' => 'Original ticket code is required for reissuance',
            'original_ticket_code.regex' => 'Invalid ticket code format. Expected format: TKT-XXXXXXXX-XXXX',
            'reason.required' => 'Reason for ticket reissuance is required',
            'reason.min' => 'Reason must be at least 10 characters long',
            'reason.in' => 'Invalid reason. Must be one of: lost, damaged, stolen, system_error, customer_request, other',
            'reason_details.max' => 'Reason details cannot exceed 1000 characters',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'original_ticket_code' => 'original ticket code',
            'reason_details' => 'reason details',
            'notify_customer' => 'customer notification flag',
            'revoke_original' => 'original ticket revocation flag',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set default values
        $this->merge([
            'notify_customer' => $this->input('notify_customer', true),
            'revoke_original' => $this->input('revoke_original', true),
        ]);
    }
}
