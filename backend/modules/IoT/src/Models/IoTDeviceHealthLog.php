<?php

namespace Modules\IoT\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IoTDeviceHealthLog extends Model
{
    protected $table = 'iot_device_health_logs';

    protected $fillable = [
        'iot_device_id',
        'status',
        'response_time_ms',
        'health_data',
        'error_message',
        'checked_at',
    ];

    protected $casts = [
        'health_data' => 'array',
        'checked_at' => 'datetime',
        'response_time_ms' => 'integer',
    ];

    /**
     * Get the device this health log belongs to
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(IoTDevice::class, 'iot_device_id');
    }

    /**
     * Scope for recent logs
     */
    public function scopeRecent($query, $hours = 24)
    {
        return $query->where('checked_at', '>=', now()->subHours($hours));
    }

    /**
     * Scope for specific status
     */
    public function scopeWithStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope for date range
     */
    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('checked_at', [$startDate, $endDate]);
    }

    /**
     * Check if this log indicates the device is healthy
     */
    public function isHealthy(): bool
    {
        return in_array($this->status, ['online']);
    }

    /**
     * Get formatted response time
     */
    public function getFormattedResponseTimeAttribute(): string
    {
        if (! $this->response_time_ms) {
            return 'N/A';
        }

        if ($this->response_time_ms < 1000) {
            return $this->response_time_ms.'ms';
        }

        return round($this->response_time_ms / 1000, 2).'s';
    }
}
