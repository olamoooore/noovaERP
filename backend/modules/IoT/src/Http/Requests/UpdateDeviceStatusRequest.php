<?php

namespace Modules\IoT\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'uuid'],
            'status' => ['required', 'string', 'in:ready,busy,offline,error'],
            'message' => ['nullable', 'string', 'max:500'],
            'error_code' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'device_id.required' => 'Device ID is required',
            'device_id.uuid' => 'Device ID must be a valid UUID',
            'status.required' => 'Status is required',
            'status.in' => 'Status must be ready, busy, offline, or error',
        ];
    }
}
