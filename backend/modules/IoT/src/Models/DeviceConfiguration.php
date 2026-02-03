<?php

namespace Modules\IoT\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceConfiguration extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'agent_id',
        'device_type',
        'driver_type',
        'name',
        'enabled',
        'connection_info',
        'driver_config',
        'capabilities',
        'metadata',
        'current_status',
        'status_message',
        'last_seen_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'connection_info' => 'array',
        'driver_config' => 'array',
        'capabilities' => 'array',
        'metadata' => 'array',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Get the discovered device that was configured into this device.
     */
    public function discoveredDevice(): HasOne
    {
        return $this->hasOne(DiscoveredDevice::class, 'configured_device_id');
    }

    /**
     * Get the status history for this device.
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(DeviceStatusHistory::class, 'device_id');
    }

    /**
     * Scope to get only enabled devices.
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope to filter by device type.
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('device_type', $type);
    }

    /**
     * Scope to filter by agent.
     */
    public function scopeByAgent($query, string $agentId)
    {
        return $query->where('agent_id', $agentId);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('current_status', $status);
    }

    /**
     * Update device status and record in history.
     */
    public function updateStatus(string $status, ?string $message = null, ?string $errorCode = null): void
    {
        $this->update([
            'current_status' => $status,
            'status_message' => $message,
            'last_seen_at' => now(),
        ]);

        // Record in history
        $this->statusHistory()->create([
            'agent_id' => $this->agent_id,
            'status' => $status,
            'message' => $message,
            'error_code' => $errorCode,
            'recorded_at' => now(),
        ]);
    }

    /**
     * Check if device is online (seen in last 5 minutes).
     */
    public function isOnline(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->diffInMinutes(now()) < 5;
    }

    /**
     * Check if device has a specific capability.
     */
    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? []);
    }
}
