<?php

namespace Modules\EventManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelBookingRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:500', 'min:3'],
            'refund_requested' => ['boolean'],
            'notify_customer' => ['boolean'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Cancellation reason is required.',
            'reason.min' => 'Cancellation reason must be at least 3 characters.',
            'reason.max' => 'Cancellation reason cannot exceed 500 characters.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'reason' => 'cancellation reason',
            'refund_requested' => 'refund request',
            'notify_customer' => 'customer notification',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Ensure boolean fields are properly cast
        if ($this->has('refund_requested')) {
            $this->merge([
                'refund_requested' => filter_var($this->refund_requested, FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        if ($this->has('notify_customer')) {
            $this->merge([
                'notify_customer' => filter_var($this->notify_customer, FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }
}
