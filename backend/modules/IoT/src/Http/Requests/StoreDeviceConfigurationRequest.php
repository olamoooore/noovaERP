<?php

namespace Modules\IoT\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    public function rules(): array
    {
        return [
            'device_type' => ['required', 'string', 'in:printer,scanner,terminal'],
            'driver_type' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'enabled' => ['boolean'],
            'connection_type' => ['nullable', 'string', 'in:agent,mqtt,http,webhook'],
            'connection_info' => ['required', 'array'],
            'driver_config' => ['nullable', 'array'],
            'capabilities' => ['array'],
            'capabilities.*' => ['string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'device_type.required' => 'Device type is required',
            'device_type.in' => 'Device type must be printer, scanner, or terminal',
            'driver_type.required' => 'Driver type is required',
            'name.required' => 'Device name is required',
            'connection_info.required' => 'Connection information is required',
        ];
    }
}
