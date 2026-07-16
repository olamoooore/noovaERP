<?php

namespace Modules\EventManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentCallbackRequest extends FormRequest
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
            'booking_reference' => ['required', 'string', 'exists:bookings,booking_reference'],
            'payment_reference' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:confirmed,success,failed,cancelled'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'transaction_id' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'booking_reference.required' => 'Booking reference is required.',
            'booking_reference.exists' => 'Invalid booking reference.',
            'payment_reference.required' => 'Payment reference is required.',
            'status.required' => 'Payment status is required.',
            'status.in' => 'Payment status must be one of: confirmed, success, failed, cancelled.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'booking_reference' => 'booking reference',
            'payment_reference' => 'payment reference',
            'status' => 'payment status',
            'amount' => 'payment amount',
            'currency' => 'currency',
            'payment_method' => 'payment method',
            'transaction_id' => 'transaction ID',
        ];
    }
}
