<?php

namespace Modules\IoT\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DeviceTemplate extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'device_type',
        'driver_type',
        'vendor_id',
        'product_id',
        'manufacturer',
        'model',
        'default_config',
        'capabilities',
        'description',
        'icon',
        'is_active',
    ];

    protected $casts = [
        'default_config' => 'array',
        'capabilities' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Scope to get only active templates.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by device type.
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('device_type', $type);
    }

    /**
     * Scope to filter by vendor and product ID.
     */
    public function scopeByVendorProduct($query, ?string $vendorId, ?string $productId)
    {
        if ($vendorId && $productId) {
            return $query->where('vendor_id', $vendorId)
                        ->where('product_id', $productId);
        }
        return $query;
    }

    /**
     * Scope to filter by manufacturer and model.
     */
    public function scopeByManufacturerModel($query, ?string $manufacturer, ?string $model)
    {
        if ($manufacturer && $model) {
            return $query->where('manufacturer', $manufacturer)
                        ->where('model', $model);
        }
        return $query;
    }

    /**
     * Check if this template matches a discovered device.
     */
    public function matchesDevice(DiscoveredDevice $device): bool
    {
        // Try exact VID/PID match
        if ($this->vendor_id && $this->product_id &&
            $device->vendor_id && $device->product_id) {
            if ($this->vendor_id === $device->vendor_id &&
                $this->product_id === $device->product_id) {
                return true;
            }
        }

        // Try manufacturer/model match
        if ($this->manufacturer && $this->model &&
            $device->manufacturer && $device->model) {
            if (strcasecmp($this->manufacturer, $device->manufacturer) === 0 &&
                strcasecmp($this->model, $device->model) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get configuration for creating a device from this template.
     */
    public function getDeviceConfiguration(array $overrides = []): array
    {
        return array_merge([
            'device_type' => $this->device_type,
            'driver_type' => $this->driver_type,
            'driver_config' => $this->default_config,
            'capabilities' => $this->capabilities,
        ], $overrides);
    }
}
