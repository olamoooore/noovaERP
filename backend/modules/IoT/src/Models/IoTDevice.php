<?php

namespace Modules\IoT\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\IoT\Enums\ConnectionType;
use Modules\IoT\Enums\DeviceStatus;
use Modules\IoT\Enums\DeviceType;

class IoTDevice extends Model
{
    use SoftDeletes;

    protected $table = 'iot_devices';

    protected $fillable = [
        'name',
        'type',
        'vendor',
        'agent_id',
        'connection_type',
        'config',
        'status',
        'last_seen_at',
        'is_active',
        'template_id',
    ];

    protected $casts = [
        'config' => 'encrypted:array',
        'last_seen_at' => 'datetime',
        'is_active' => 'boolean',
        'type' => DeviceType::class,
        'status' => DeviceStatus::class,
        'connection_type' => ConnectionType::class,
    ];

    /**
     * Get the logs for this device.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(IoTDeviceLog::class, 'iot_device_id');
    }

    /**
     * Get the bindings for this device.
     */
    public function bindings(): HasMany
    {
        return $this->hasMany(IoTDeviceBinding::class, 'iot_device_id');
    }

    /**
     * Get the template this device was created from.
     */
    public function template()
    {
        return $this->belongsTo(IoTDeviceTemplate::class, 'template_id');
    }

    /**
     * Get the health logs for this device.
     */
    public function healthLogs(): HasMany
    {
        return $this->hasMany(IoTDeviceHealthLog::class, 'iot_device_id');
    }

    /**
     * Get the metrics for this device.
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(IoTDeviceMetric::class, 'iot_device_id');
    }

    /**
     * Get the latest health log for this device.
     */
    public function latestHealthLog()
    {
        return $this->hasOne(IoTDeviceHealthLog::class, 'iot_device_id')->latest('checked_at');
    }

    /**
     * Check if device is online.
     */
    public function isOnline(): bool
    {
        return $this->status === DeviceStatus::ONLINE;
    }

    /**
     * Mark device as online.
     */
    public function markOnline(): void
    {
        $this->update([
            'status' => DeviceStatus::ONLINE,
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Mark device as offline.
     */
    public function markOffline(): void
    {
        $this->update([
            'status' => DeviceStatus::OFFLINE,
        ]);
    }

    /**
     * Log an action for this device.
     */
    public function logAction(string $action, string $status, ?array $requestData = null, ?array $responseData = null, ?string $errorMessage = null): IoTDeviceLog
    {
        return $this->logs()->create([
            'action' => $action,
            'status' => $status,
            'request_data' => $requestData,
            'response_data' => $responseData,
            'error_message' => $errorMessage,
        ]);
    }
}
