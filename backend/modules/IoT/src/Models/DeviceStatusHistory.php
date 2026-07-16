<?php

namespace Modules\IoT\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceStatusHistory extends Model
{
    protected $table = 'device_status_history';

    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'agent_id',
        'status',
        'message',
        'error_code',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    /**
     * Get the device configuration this history belongs to.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(DeviceConfiguration::class, 'device_id');
    }

    /**
     * Scope to get recent history.
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('recorded_at', '>=', now()->subHours($hours));
    }

    /**
     * Scope to filter by device.
     */
    public function scopeByDevice($query, string $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get only error statuses.
     */
    public function scopeErrors($query)
    {
        return $query->where('status', 'error');
    }

    /**
     * Scope to order by most recent first.
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('recorded_at', 'desc');
    }
}
