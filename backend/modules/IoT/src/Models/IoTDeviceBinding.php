<?php

namespace Modules\IoT\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IoTDeviceBinding extends Model
{
    protected $table = 'iot_device_bindings';

    protected $fillable = [
        'station_id',
        'iot_device_id',
        'binding_type',
    ];

    /**
     * Get the device for this binding.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(IoTDevice::class, 'iot_device_id');
    }

    /**
     * Get bindings for a specific station.
     */
    public static function getStationDevices(string $stationId): array
    {
        return static::where('station_id', $stationId)
            ->with('device')
            ->get()
            ->mapWithKeys(function ($binding) {
                return [$binding->binding_type => $binding->device];
            })
            ->toArray();
    }
}
