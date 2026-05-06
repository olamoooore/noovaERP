<?php

namespace Modules\IoT\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    public function rules(): array
    {
        return [
            'device_type' => ['sometimes', 'string', 'in:printer,scanner,terminal'],
            'driver_type' => ['sometimes', 'string', 'max:100'],
            'name' => ['sometimes', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'connection_type' => ['sometimes', 'string', 'in:agent,mqtt,http,webhook'],
            'connection_info' => ['sometimes', 'array'],
            'driver_config' => ['sometimes', 'array'],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => ['string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
