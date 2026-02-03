<?php

namespace Modules\IoT\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IoTDeviceLog extends Model
{
    protected $table = 'iot_device_logs';

    public $timestamps = false;

    protected $fillable = [
        'iot_device_id',
        'action',
        'status',
        'request_data',
        'response_data',
        'error_message',
    ];

    protected $casts = [
        'request_data' => 'array',
        'response_data' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Get the device that owns this log.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(IoTDevice::class, 'iot_device_id');
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($log) {
            $log->created_at = now();
        });
    }
}
