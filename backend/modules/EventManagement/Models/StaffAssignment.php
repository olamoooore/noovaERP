<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StaffAssignment Model
 * 
 * Represents staff role assignments per event with zone-based access control
 * and performance tracking.
 * 
 * @property int $id
 * @property string $tenant_id
 * @property int $event_id
 * @property string $staff_user_id
 * @property string $role
 * @property string|null $zone
 * @property string|null $responsibilities
 * @property array|null $permissions
 * @property \Carbon\Carbon|null $shift_start
 * @property \Carbon\Carbon|null $shift_end
 * @property bool $is_lead
 * @property bool $can_override
 * @property string|null $contact_number
 * @property string|null $emergency_contact
 * @property string|null $special_instructions
 * @property string $status
 * @property \Carbon\Carbon|null $checked_in_at
 * @property \Carbon\Carbon|null $checked_out_at
 * @property int|null $performance_rating
 * @property string|null $performance_notes
 * @property array|null $metadata
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * @property string|null $created_by
 * @property string|null $updated_by
 */
class StaffAssignment extends Model
{
    use SoftDeletes;

    protected $table = 'staff_assignments';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'staff_user_id',
        'role',
        'zone',
        'responsibilities',
        'permissions',
        'shift_start',
        'shift_end',
        'is_lead',
        'can_override',
        'contact_number',
        'emergency_contact',
        'special_instructions',
        'status',
        'checked_in_at',
        'checked_out_at',
        'performance_rating',
        'performance_notes',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'permissions' => 'array',
        'metadata' => 'array',
        'shift_start' => 'datetime',
        'shift_end' => 'datetime',
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'is_lead' => 'boolean',
        'can_override' => 'boolean',
        'performance_rating' => 'integer',
    ];

    protected $dates = [
        'shift_start',
        'shift_end',
        'checked_in_at',
        'checked_out_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Valid staff roles
     */
    const ROLES = [
        'manager',
        'coordinator',
        'security',
        'usher',
        'technical',
        'medical',
        'customer_service',
        'merchandise',
        'cleaning',
        'volunteer',
    ];

    /**
     * Valid staff statuses
     */
    const STATUSES = [
        'assigned',
        'checked_in',
        'active',
        'break',
        'checked_out',
        'absent',
    ];

    /**
     * Get the event this assignment belongs to
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the operational zone this assignment is for
     */
    public function operationalZone(): BelongsTo
    {
        return $this->belongsTo(OperationalZone::class, 'zone', 'zone_code')
                    ->where('event_id', $this->event_id)
                    ->where('tenant_id', $this->tenant_id);
    }

    /**
     * Check if staff member is currently on duty
     */
    public function isOnDuty(): bool
    {
        return in_array($this->status, ['checked_in', 'active']);
    }

    /**
     * Check if staff member can access a specific zone
     */
    public function canAccessZone(string $zoneCode): bool
    {
        // Lead staff can access any zone
        if ($this->is_lead) {
            return true;
        }

        // Staff can access their assigned zone
        if ($this->zone === $zoneCode) {
            return true;
        }

        // Check permissions for additional zone access
        $permissions = $this->permissions ?? [];
        return in_array("zone_access_{$zoneCode}", $permissions);
    }

    /**
     * Check if staff member has a specific permission
     */
    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions ?? [];
        return in_array($permission, $permissions);
    }

    /**
     * Get shift duration in hours
     */
    public function getShiftDurationAttribute(): ?float
    {
        if (!$this->shift_start || !$this->shift_end) {
            return null;
        }

        return $this->shift_start->diffInHours($this->shift_end);
    }

    /**
     * Get actual work duration in hours
     */
    public function getWorkDurationAttribute(): ?float
    {
        if (!$this->checked_in_at) {
            return null;
        }

        $endTime = $this->checked_out_at ?? now();
        return $this->checked_in_at->diffInHours($endTime);
    }

    /**
     * Check if staff member is late for their shift
     */
    public function isLate(): bool
    {
        if (!$this->shift_start || !$this->checked_in_at) {
            return false;
        }

        return $this->checked_in_at->isAfter($this->shift_start->addMinutes(15));
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
     * Scope to filter by role
     */
    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Scope to filter by zone
     */
    public function scopeByZone($query, string $zone)
    {
        return $query->where('zone', $zone);
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get lead staff only
     */
    public function scopeLeadsOnly($query)
    {
        return $query->where('is_lead', true);
    }

    /**
     * Scope to get currently active staff
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['checked_in', 'active']);
    }
}