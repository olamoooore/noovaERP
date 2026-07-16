<?php

namespace Modules\EventManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateEventRequest extends FormRequest
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
     * Requirements: 11.1, 11.2, 11.3 - Validate event type, acquisition mode, and sale window
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255', 'min:3'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event_date' => ['required', 'date', 'after:now'],
            'venue' => ['required', 'string', 'max:255', 'min:3'],
            'total_capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            'terms_conditions' => ['nullable', 'string', 'max:10000'],
            'ticket_types' => ['nullable', 'array', 'min:1'],
            'ticket_types.*.name' => ['required_with:ticket_types', 'string', 'max:255'],
            'ticket_types.*.price' => ['required_with:ticket_types', 'numeric', 'min:0'],
            'ticket_types.*.capacity' => ['required_with:ticket_types', 'integer', 'min:1'],
            'ticket_types.*.description' => ['nullable', 'string', 'max:1000'],

            // Phase 4 requirements
            'event_type_id' => ['required', 'integer', 'exists:event_types,id'],
            'ticket_acquisition_mode' => ['required', 'string', 'in:buy,bid'],
            'sale_start_date' => ['required', 'date', 'before:sale_end_date'],
            'sale_end_date' => ['required', 'date', 'after:sale_start_date'],
            'merchandise_product_ids' => ['sometimes', 'array'],
            'merchandise_product_ids.*' => ['integer', 'exists:products,id'],
            'bundle_product_ids' => ['sometimes', 'array'],
            'bundle_product_ids.*' => ['integer', 'exists:products,id'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     * Requirements: 11.4 - Descriptive validation error messages
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Event title is required.',
            'title.min' => 'Event title must be at least 3 characters.',
            'event_date.required' => 'Event date is required.',
            'event_date.after' => 'Event date must be in the future.',
            'venue.required' => 'Venue is required.',
            'venue.min' => 'Venue must be at least 3 characters.',
            'total_capacity.required' => 'Total capacity is required.',
            'total_capacity.min' => 'Total capacity must be at least 1.',
            'total_capacity.max' => 'Total capacity cannot exceed 100,000.',
            'ticket_types.*.name.required_with' => 'Ticket type name is required.',
            'ticket_types.*.price.required_with' => 'Ticket type price is required.',
            'ticket_types.*.price.min' => 'Ticket type price cannot be negative.',
            'ticket_types.*.capacity.required_with' => 'Ticket type capacity is required.',
            'ticket_types.*.capacity.min' => 'Ticket type capacity must be at least 1.',

            // Phase 4 validation messages
            'event_type_id.required' => 'Event type is required. Please select a valid event type.',
            'event_type_id.exists' => 'The selected event type is invalid.',
            'ticket_acquisition_mode.required' => 'Ticket acquisition mode is required. Please select either "buy" or "bid".',
            'ticket_acquisition_mode.in' => 'Ticket acquisition mode must be either "buy" or "bid".',
            'sale_start_date.required' => 'Sale start date is required. Please specify when ticket sales should begin.',
            'sale_start_date.before' => 'Sale start date must be before the sale end date.',
            'sale_end_date.required' => 'Sale end date is required. Please specify when ticket sales should end.',
            'sale_end_date.after' => 'Sale end date must be after the sale start date.',
            'merchandise_product_ids.*.exists' => 'One or more selected merchandise products are invalid.',
            'bundle_product_ids.*.exists' => 'One or more selected bundle products are invalid.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'title' => 'event title',
            'event_date' => 'event date',
            'venue' => 'venue',
            'total_capacity' => 'total capacity',
            'terms_conditions' => 'terms and conditions',
            'ticket_types.*.name' => 'ticket type name',
            'ticket_types.*.price' => 'ticket type price',
            'ticket_types.*.capacity' => 'ticket type capacity',
            'ticket_types.*.description' => 'ticket type description',
            'merchandise_product_ids' => 'linked merchandise products',
            'bundle_product_ids' => 'linked bundle products',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Validate that ticket types capacity doesn't exceed total capacity
            if ($this->has('ticket_types') && $this->has('total_capacity')) {
                $totalTicketCapacity = collect($this->ticket_types)->sum('capacity');
                if ($totalTicketCapacity > $this->total_capacity) {
                    $validator->errors()->add(
                        'ticket_types',
                        'Total ticket type capacity cannot exceed event total capacity.'
                    );
                }
            }
        });
    }
}
