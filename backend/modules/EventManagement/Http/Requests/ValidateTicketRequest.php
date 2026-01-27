<?php

namespace Modules\EventManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ValidateTicketRequest extends FormRequest
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
            'ticket_code' => [
                'required',
                'string',
                'regex:/^TKT-[A-Z0-9]{8}-[A-Z0-9]{4}$/',
                'max:20'
            ],
            'event_id' => [
                'sometimes',
                'integer',
                'min:1'
            ],
            'validation_context' => [
                'sometimes',
                'array'
            ],
            'validation_context.location' => [
                'sometimes',
                'string',
                'max:255'
            ],
            'validation_context.scanner_id' => [
                'sometimes',
                'string',
                'max:100'
            ]
        ];
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'ticket_code.required' => 'Ticket code is required for validation',
            'ticket_code.regex' => 'Invalid ticket code format. Expected format: TKT-XXXXXXXX-XXXX',
            'event_id.integer' => 'Event ID must be a valid integer',
            'event_id.min' => 'Event ID must be greater than 0',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'ticket_code' => 'ticket code',
            'event_id' => 'event ID',
            'validation_context.location' => 'validation location',
            'validation_context.scanner_id' => 'scanner ID',
        ];
    }
}