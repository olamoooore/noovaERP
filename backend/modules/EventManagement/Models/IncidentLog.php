<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * IncidentLog Model
 *
 * Represents operational incidents and administrative overrides
 * with full audit trails and escalation workflows.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $event_id
 * @property int|null $zone_id
 * @property int|null $counter_id
 * @property string $incident_type
 * @property string $severity
 * @property string $priority
 * @property string $title
 * @property string $description
 * @property string|null $initial_assessment
 * @property array|null $affected_areas
 * @property int|null $people_affected
 * @property string $reported_by
 * @property string|null $assigned_to
 * @property \Carbon\Carbon $reported_at
 * @property \Carbon\Carbon|null $acknowledged_at
 * @property \Carbon\Carbon|null $assigned_at
 * @property string $status
 * @property string|null $resolution_notes
 * @property string|null $actions_taken
 * @property \Carbon\Carbon|null $resolved_at
 * @property string|null $resolved_by
 * @property bool $is_administrative_override
 * @property string|null $override_type
 * @property string|null $override_justification
 * @property string|null $authorized_by
 * @property array|null $override_details
 * @property bool $requires_follow_up
 * @property \Carbon\Carbon|null $follow_up_date
 * @property string|null $prevention_measures
 * @property bool $policy_update_required
 * @property array|null $notifications_sent
 * @property bool $escalated_to_management
 * @property \Carbon\Carbon|null $escalated_at
 * @property string|null $escalation_reason
 * @property array|null $metadata
 * @property array|null $attachments
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * @property string|null $created_by
 * @property string|null $updated_by
 */
class IncidentLog extends Model
{
    use SoftDeletes;

    protected $table = 'incident_logs';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'zone_id',
        'counter_id',
        'incident_type',
        'severity',
        'priority',
        'title',
        'description',
        'initial_assessment',
        'affected_areas',
        'people_affected',
        'reported_by',
        'assigned_to',
        'reported_at',
        'acknowledged_at',
        'assigned_at',
        'status',
        'resolution_notes',
        'actions_taken',
        'resolved_at',
        'resolved_by',
        'is_administrative_override',
        'override_type',
        'override_justification',
        'authorized_by',
        'override_details',
        'requires_follow_up',
        'follow_up_date',
        'prevention_measures',
        'policy_update_required',
        'notifications_sent',
        'escalated_to_management',
        'escalated_at',
        'escalation_reason',
        'metadata',
        'attachments',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'affected_areas' => 'array',
        'override_details' => 'array',
        'notifications_sent' => 'array',
        'metadata' => 'array',
        'attachments' => 'array',
        'reported_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'assigned_at' => 'datetime',
        'resolved_at' => 'datetime',
        'escalated_at' => 'datetime',
        'follow_up_date' => 'date',
        'is_administrative_override' => 'boolean',
        'requires_follow_up' => 'boolean',
        'policy_update_required' => 'boolean',
        'escalated_to_management' => 'boolean',
        'people_affected' => 'integer',
    ];

    protected $dates = [
        'reported_at',
        'acknowledged_at',
        'assigned_at',
        'resolved_at',
        'escalated_at',
        'follow_up_date',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Valid incident types
     */
    const INCIDENT_TYPES = [
        'security',
        'medical',
        'technical',
        'crowd_control',
        'weather',
        'equipment_failure',
        'staff_issue',
        'customer_complaint',
        'administrative_override',
        'emergency',
        'other',
    ];

    /**
     * Valid severity levels
     */
    const SEVERITIES = [
        'low',
        'medium',
        'high',
        'critical',
    ];

    /**
     * Valid priority levels
     */
    const PRIORITIES = [
        'low',
        'normal',
        'high',
        'urgent',
    ];

    /**
     * Valid incident statuses
     */
    const STATUSES = [
        'open',
        'acknowledged',
        'in_progress',
        'resolved',
        'closed',
        'escalated',
    ];

    /**
     * Get the event this incident belongs to
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the operational zone this incident occurred in
     */
    public function operationalZone(): BelongsTo
    {
        return $this->belongsTo(OperationalZone::class, 'zone_id');
    }

    /**
     * Get the check-in counter this incident occurred at
     */
    public function checkInCounter(): BelongsTo
    {
        return $this->belongsTo(CheckInCounter::class, 'counter_id');
    }

    /**
     * Check if incident is open
     */
    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'acknowledged', 'in_progress']);
    }

    /**
     * Check if incident is resolved
     */
    public function isResolved(): bool
    {
        return in_array($this->status, ['resolved', 'closed']);
    }

    /**
     * Check if incident is critical
     */
    public function isCritical(): bool
    {
        return $this->severity === 'critical';
    }

    /**
     * Check if incident is urgent
     */
    public function isUrgent(): bool
    {
        return $this->priority === 'urgent';
    }

    /**
     * Check if incident is an administrative override
     */
    public function isAdministrativeOverride(): bool
    {
        return $this->is_administrative_override;
    }

    /**
     * Check if incident has been escalated
     */
    public function isEscalated(): bool
    {
        return $this->escalated_to_management;
    }

    /**
     * Acknowledge the incident
     */
    public function acknowledge(?string $acknowledgedBy = null): void
    {
        $this->status = 'acknowledged';
        $this->acknowledged_at = now();

        if ($acknowledgedBy) {
            $this->assigned_to = $acknowledgedBy;
            $this->assigned_at = now();
        }

        $this->save();
    }

    /**
     * Assign incident to staff member
     */
    public function assignTo(string $staffId): void
    {
        $this->assigned_to = $staffId;
        $this->assigned_at = now();

        if ($this->status === 'open') {
            $this->status = 'acknowledged';
            $this->acknowledged_at = now();
        }

        $this->save();
    }

    /**
     * Mark incident as in progress
     */
    public function markInProgress(): void
    {
        $this->status = 'in_progress';
        $this->save();
    }

    /**
     * Resolve the incident
     */
    public function resolve(string $resolvedBy, string $resolutionNotes, ?string $actionsTaken = null): void
    {
        $this->status = 'resolved';
        $this->resolved_at = now();
        $this->resolved_by = $resolvedBy;
        $this->resolution_notes = $resolutionNotes;

        if ($actionsTaken) {
            $this->actions_taken = $actionsTaken;
        }

        $this->save();
    }

    /**
     * Close the incident
     */
    public function close(): void
    {
        $this->status = 'closed';
        $this->save();
    }

    /**
     * Escalate incident to management
     */
    public function escalate(string $reason): void
    {
        $this->escalated_to_management = true;
        $this->escalated_at = now();
        $this->escalation_reason = $reason;
        $this->status = 'escalated';
        $this->save();
    }

    /**
     * Get resolution time in minutes
     */
    public function getResolutionTimeAttribute(): ?int
    {
        if (! $this->resolved_at) {
            return null;
        }

        return $this->reported_at->diffInMinutes($this->resolved_at);
    }

    /**
     * Get response time in minutes (time to acknowledge)
     */
    public function getResponseTimeAttribute(): ?int
    {
        if (! $this->acknowledged_at) {
            return null;
        }

        return $this->reported_at->diffInMinutes($this->acknowledged_at);
    }

    /**
     * Get age of incident in minutes
     */
    public function getAgeInMinutesAttribute(): int
    {
        $endTime = $this->resolved_at ?? now();

        return $this->reported_at->diffInMinutes($endTime);
    }

    /**
     * Get severity color for UI
     */
    public function getSeverityColorAttribute(): string
    {
        return match ($this->severity) {
            'critical' => 'red',
            'high' => 'orange',
            'medium' => 'yellow',
            'low' => 'green',
            default => 'gray',
        };
    }

    /**
     * Get priority color for UI
     */
    public function getPriorityColorAttribute(): string
    {
        return match ($this->priority) {
            'urgent' => 'red',
            'high' => 'orange',
            'normal' => 'blue',
            'low' => 'gray',
            default => 'gray',
        };
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
     * Scope to filter by incident type
     */
    public function scopeByType($query, string $incidentType)
    {
        return $query->where('incident_type', $incidentType);
    }

    /**
     * Scope to filter by severity
     */
    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Scope to filter by priority
     */
    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get open incidents only
     */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'acknowledged', 'in_progress']);
    }

    /**
     * Scope to get resolved incidents only
     */
    public function scopeResolved($query)
    {
        return $query->whereIn('status', ['resolved', 'closed']);
    }

    /**
     * Scope to get critical incidents only
     */
    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    /**
     * Scope to get urgent incidents only
     */
    public function scopeUrgent($query)
    {
        return $query->where('priority', 'urgent');
    }

    /**
     * Scope to get administrative overrides only
     */
    public function scopeAdministrativeOverrides($query)
    {
        return $query->where('is_administrative_override', true);
    }

    /**
     * Scope to get escalated incidents only
     */
    public function scopeEscalated($query)
    {
        return $query->where('escalated_to_management', true);
    }

    /**
     * Scope to get incidents requiring follow-up
     */
    public function scopeRequiringFollowUp($query)
    {
        return $query->where('requires_follow_up', true);
    }
}
