<?php

namespace Modules\IoT\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportDiscoveredDevicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    public function rules(): array
    {
        return [
            'devices' => ['required', 'array', 'min:1'],
            'devices.*.vendor_id' => ['required', 'string', 'max:50'],
            'devices.*.product_id' => ['required', 'string', 'max:50'],
            'devices.*.manufacturer' => ['nullable', 'string', 'max:255'],
            'devices.*.model' => ['nullable', 'string', 'max:255'],
            'devices.*.serial_number' => ['nullable', 'string', 'max:255'],
            'devices.*.port' => ['nullable', 'string', 'max:100'],
            'devices.*.connection_type' => ['required', 'string', 'in:usb,serial,hid'],
        ];
    }

    public function messages(): array
    {
        return [
            'devices.required' => 'Devices array is required',
            'devices.*.vendor_id.required' => 'Vendor ID is required for each device',
            'devices.*.product_id.required' => 'Product ID is required for each device',
            'devices.*.connection_type.required' => 'Connection type is required for each device',
            'devices.*.connection_type.in' => 'Connection type must be usb, serial, or hid',
        ];
    }
}
