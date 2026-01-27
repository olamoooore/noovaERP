<?php

namespace Modules\EventManagement\Http\Requests;

use Modules\EventManagement\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;

class CreateBookingRequest extends FormRequest
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
        return Booking::getBookingValidationRules();
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'buyer_name.required' => 'Buyer name is required.',
            'buyer_name.min' => 'Buyer name must be at least 2 characters.',
            'buyer_email.required' => 'Buyer email is required.',
            'buyer_email.email' => 'Please provide a valid email address.',
            'buyer_phone.required' => 'Buyer phone number is required.',
            'buyer_phone.regex' => 'Please provide a valid phone number.',
            'consent_pdpa.required' => 'PDPA consent is required.',
            'consent_pdpa.accepted' => 'You must accept the PDPA terms to proceed.',
            'attendees.required' => 'At least one attendee is required.',
            'attendees.min' => 'At least one attendee is required.',
            'attendees.*.attendee_name.required' => 'Attendee name is required.',
            'attendees.*.attendee_name.min' => 'Attendee name must be at least 2 characters.',
            'attendees.*.attendee_email.email' => 'Please provide a valid email address for the attendee.',
            'attendees.*.attendee_phone.regex' => 'Please provide a valid phone number for the attendee.',
            'attendees.*.ticket_type_id.required' => 'Ticket type is required for each attendee.',
            'attendees.*.ticket_type_id.exists' => 'Selected ticket type is invalid.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'buyer_name' => 'buyer name',
            'buyer_email' => 'buyer email',
            'buyer_phone' => 'buyer phone',
            'consent_pdpa' => 'PDPA consent',
            'consent_marketing' => 'marketing consent',
            'attendees.*.attendee_name' => 'attendee name',
            'attendees.*.attendee_email' => 'attendee email',
            'attendees.*.attendee_phone' => 'attendee phone',
            'attendees.*.ticket_type_id' => 'ticket type',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Ensure consent_pdpa is boolean
        if ($this->has('consent_pdpa')) {
            $this->merge([
                'consent_pdpa' => filter_var($this->consent_pdpa, FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        // Ensure consent_marketing is boolean
        if ($this->has('consent_marketing')) {
            $this->merge([
                'consent_marketing' => filter_var($this->consent_marketing, FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }
}