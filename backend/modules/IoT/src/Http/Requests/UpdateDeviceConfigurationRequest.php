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
            'connection_info' => ['sometimes', 'array'],
            'connection_info.vendor_id' => ['nullable', 'string', 'max:50'],
            'connection_info.product_id' => ['nullable', 'string', 'max:50'],
            'connection_info.port' => ['nullable', 'string', 'max:500'], // Increased for long USB paths
            'connection_info.printer_name' => ['nullable', 'string', 'max:255'],
            'driver_config' => ['sometimes', 'array'],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => ['string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
