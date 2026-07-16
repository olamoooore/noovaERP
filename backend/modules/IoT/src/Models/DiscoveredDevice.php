<?php

namespace Modules\IoT\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscoveredDevice extends Model
{
    use HasUuids;

    protected $fillable = [
        'agent_id',
        'vendor_id',
        'product_id',
        'manufacturer',
        'model',
        'serial_number',
        'port',
        'connection_type',
        'status',
        'configured_device_id',
        'discovered_at',
        'last_seen_at',
    ];

    protected $casts = [
        'discovered_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Get the device configuration if this device has been configured.
     */
    public function configuredDevice(): BelongsTo
    {
        return $this->belongsTo(DeviceConfiguration::class, 'configured_device_id');
    }

    /**
     * Scope to get only discovered (not configured) devices.
     */
    public function scopeDiscovered($query)
    {
        return $query->where('status', 'discovered');
    }

    /**
     * Scope to get only configured devices.
     */
    public function scopeConfigured($query)
    {
        return $query->where('status', 'configured');
    }

    /**
     * Scope to filter by agent.
     */
    public function scopeByAgent($query, string $agentId)
    {
        return $query->where('agent_id', $agentId);
    }

    /**
     * Scope to filter by connection type.
     */
    public function scopeByConnectionType($query, string $type)
    {
        return $query->where('connection_type', $type);
    }

    /**
     * Mark this device as configured.
     */
    public function markAsConfigured(string $deviceConfigId): void
    {
        $this->update([
            'status' => 'configured',
            'configured_device_id' => $deviceConfigId,
        ]);
    }

    /**
     * Mark this device as ignored.
     */
    public function markAsIgnored(): void
    {
        $this->update([
            'status' => 'ignored',
        ]);
    }

    /**
     * Update last seen timestamp.
     */
    public function updateLastSeen(): void
    {
        $this->update([
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Get a unique identifier for this device.
     */
    public function getUniqueIdentifier(): string
    {
        return sprintf(
            '%s-%s-%s',
            $this->vendor_id ?? 'unknown',
            $this->product_id ?? 'unknown',
            $this->serial_number ?? $this->port
        );
    }
}
