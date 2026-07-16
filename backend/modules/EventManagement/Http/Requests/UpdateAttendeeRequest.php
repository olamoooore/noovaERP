<?php

namespace Modules\EventManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\EventManagement\Models\BookingAttendee;

class UpdateAttendeeRequest extends FormRequest
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
        $rules = BookingAttendee::getValidationRules();

        // Remove booking_id and ticket_type_id from update rules
        unset($rules['booking_id'], $rules['ticket_type_id']);

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'attendee_name.required' => 'Attendee name is required.',
            'attendee_name.min' => 'Attendee name must be at least 2 characters.',
            'attendee_email.email' => 'Please provide a valid email address.',
            'attendee_phone.regex' => 'Please provide a valid phone number.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'attendee_name' => 'attendee name',
            'attendee_email' => 'attendee email',
            'attendee_phone' => 'attendee phone',
            'encrypted_id_info' => 'ID information',
        ];
    }
}
