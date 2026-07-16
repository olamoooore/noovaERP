<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * OperationalZone Model
 *
 * Represents event venue zones and areas for operational management
 * with capacity monitoring and access control.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $event_id
 * @property string $zone_code
 * @property string $zone_name
 * @property string|null $description
 * @property string $zone_type
 * @property int|null $max_capacity
 * @property int $current_occupancy
 * @property float $occupancy_percentage
 * @property array|null $layout_coordinates
 * @property array|null $access_points
 * @property bool $requires_special_access
 * @property bool $has_age_restriction
 * @property int|null $min_age
 * @property array|null $required_permissions
 * @property string $status
 * @property \Carbon\Carbon|null $opened_at
 * @property \Carbon\Carbon|null $closed_at
 * @property string|null $status_notes
 * @property int|null $emergency_capacity
 * @property array|null $emergency_procedures
 * @property bool $has_emergency_exit
 * @property array|null $metadata
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * @property string|null $created_by
 * @property string|null $updated_by
 */
class OperationalZone extends Model
{
    use SoftDeletes;

    protected $table = 'operational_zones';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'zone_code',
        'zone_name',
        'description',
        'zone_type',
        'max_capacity',
        'current_occupancy',
        'occupancy_percentage',
        'layout_coordinates',
        'access_points',
        'requires_special_access',
        'has_age_restriction',
        'min_age',
        'required_permissions',
        'status',
        'opened_at',
        'closed_at',
        'status_notes',
        'emergency_capacity',
        'emergency_procedures',
        'has_emergency_exit',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'layout_coordinates' => 'array',
        'access_points' => 'array',
        'required_permissions' => 'array',
        'emergency_procedures' => 'array',
        'metadata' => 'array',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'requires_special_access' => 'boolean',
        'has_age_restriction' => 'boolean',
        'has_emergency_exit' => 'boolean',
        'max_capacity' => 'integer',
        'current_occupancy' => 'integer',
        'occupancy_percentage' => 'decimal:2',
        'min_age' => 'integer',
        'emergency_capacity' => 'integer',
    ];

    protected $dates = [
        'opened_at',
        'closed_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Valid zone types
     */
    const ZONE_TYPES = [
        'entry',
        'seating',
        'standing',
        'vip',
        'merchandise',
        'food',
        'restroom',
        'emergency',
        'staff',
        'backstage',
    ];

    /**
     * Valid zone statuses
     */
    const STATUSES = [
        'inactive',
        'preparing',
        'open',
        'full',
        'closed',
        'emergency',
    ];

    /**
     * Get the event this zone belongs to
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get staff assignments for this zone
     */
    public function staffAssignments(): HasMany
    {
        return $this->hasMany(StaffAssignment::class, 'zone', 'zone_code')
            ->where('event_id', $this->event_id)
            ->where('tenant_id', $this->tenant_id);
    }

    /**
     * Get check-in counters in this zone
     */
    public function checkInCounters(): HasMany
    {
        return $this->hasMany(CheckInCounter::class, 'zone_id');
    }

    /**
     * Get incidents in this zone
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(IncidentLog::class, 'zone_id');
    }

    /**
     * Check if zone is at capacity
     */
    public function isAtCapacity(): bool
    {
        return $this->max_capacity && $this->current_occupancy >= $this->max_capacity;
    }

    /**
     * Check if zone is nearly full (90% capacity)
     */
    public function isNearlyFull(): bool
    {
        return $this->occupancy_percentage >= 90;
    }

    /**
     * Check if zone is open for access
     */
    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * Check if zone requires special access permissions
     */
    public function requiresSpecialAccess(): bool
    {
        return $this->requires_special_access;
    }

    /**
     * Check if a person can access this zone based on age
     */
    public function canAccessByAge(int $age): bool
    {
        if (! $this->has_age_restriction) {
            return true;
        }

        return $this->min_age ? $age >= $this->min_age : true;
    }

    /**
     * Update occupancy and recalculate percentage
     */
    public function updateOccupancy(int $newOccupancy): void
    {
        $this->current_occupancy = max(0, $newOccupancy);

        if ($this->max_capacity > 0) {
            $this->occupancy_percentage = ($this->current_occupancy / $this->max_capacity) * 100;
        } else {
            $this->occupancy_percentage = 0;
        }

        // Auto-update status based on occupancy
        if ($this->occupancy_percentage >= 100) {
            $this->status = 'full';
        } elseif ($this->occupancy_percentage >= 90 && $this->status !== 'full') {
            // Don't change status if it's already full
            if ($this->status === 'open') {
                $this->status = 'busy';
            }
        } elseif ($this->occupancy_percentage > 0 && $this->status === 'inactive') {
            $this->status = 'open';
        }

        $this->save();
    }

    /**
     * Add people to the zone
     */
    public function addOccupants(int $count): void
    {
        $this->updateOccupancy($this->current_occupancy + $count);
    }

    /**
     * Remove people from the zone
     */
    public function removeOccupants(int $count): void
    {
        $this->updateOccupancy($this->current_occupancy - $count);
    }

    /**
     * Get available capacity
     */
    public function getAvailableCapacityAttribute(): int
    {
        if (! $this->max_capacity) {
            return 0;
        }

        return max(0, $this->max_capacity - $this->current_occupancy);
    }

    /**
     * Get occupancy status description
     */
    public function getOccupancyStatusAttribute(): string
    {
        if ($this->occupancy_percentage >= 100) {
            return 'Full';
        } elseif ($this->occupancy_percentage >= 90) {
            return 'Nearly Full';
        } elseif ($this->occupancy_percentage >= 70) {
            return 'Busy';
        } elseif ($this->occupancy_percentage > 0) {
            return 'Active';
        } else {
            return 'Empty';
        }
    }

    /**
     * Scope to filter by tenant
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter by event
     */
    public function scopeForEvent($query, int $eventId)
    {
        return $query->where('event_id', $eventId);
    }

    /**
     * Scope to filter by zone type
     */
    public function scopeByType($query, string $zoneType)
    {
        return $query->where('zone_type', $zoneType);
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get open zones only
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    /**
     * Scope to get zones at or near capacity
     */
    public function scopeNearCapacity($query, float $threshold = 90.0)
    {
        return $query->where('occupancy_percentage', '>=', $threshold);
    }

    /**
     * Scope to get zones requiring special access
     */
    public function scopeSpecialAccess($query)
    {
        return $query->where('requires_special_access', true);
    }
}
