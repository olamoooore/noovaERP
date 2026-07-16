<?php

namespace Modules\EventManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEventRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:255', 'min:3'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event_date' => ['sometimes', 'date', 'after:now'],
            'venue' => ['sometimes', 'string', 'max:255', 'min:3'],
            'terms_conditions' => ['nullable', 'string', 'max:10000'],
            'merchandise_product_ids' => ['sometimes', 'array'],
            'merchandise_product_ids.*' => ['integer', 'exists:products,id'],
            'bundle_product_ids' => ['sometimes', 'array'],
            'bundle_product_ids.*' => ['integer', 'exists:products,id'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'title.min' => 'Event title must be at least 3 characters.',
            'event_date.after' => 'Event date must be in the future.',
            'venue.min' => 'Venue must be at least 3 characters.',
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
            'terms_conditions' => 'terms and conditions',
            'merchandise_product_ids' => 'linked merchandise products',
            'bundle_product_ids' => 'linked bundle products',
        ];
    }
}
