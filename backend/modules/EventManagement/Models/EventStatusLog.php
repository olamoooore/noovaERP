<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EventStatusLog Model
 *
 * Represents real-time event status tracking with comprehensive
 * change logging and impact assessment.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $event_id
 * @property string $status_type
 * @property string|null $previous_status
 * @property string $current_status
 * @property string|null $status_description
 * @property string $changed_by
 * @property string|null $change_reason
 * @property array|null $status_data
 * @property array|null $affected_components
 * @property \Carbon\Carbon $status_changed_at
 * @property \Carbon\Carbon|null $effective_until
 * @property int|null $duration_minutes
 * @property string $impact_level
 * @property int|null $attendees_affected
 * @property array|null $zones_affected
 * @property string|null $impact_description
 * @property bool $public_announcement_made
 * @property string|null $announcement_text
 * @property array|null $notification_channels
 * @property \Carbon\Carbon|null $announced_at
 * @property bool $requires_action
 * @property string|null $recommended_actions
 * @property array|null $staff_assignments_changed
 * @property bool $escalation_triggered
 * @property array|null $metadata
 * @property \Carbon\Carbon|null $created_at
 * @property string|null $created_by
 */
class EventStatusLog extends Model
{
    protected $table = 'event_status_logs';

    // No soft deletes - status logs are permanent records
    public $timestamps = true;

    const UPDATED_AT = null; // Only created_at, no updated_at

    protected $fillable = [
        'tenant_id',
        'event_id',
        'status_type',
        'previous_status',
        'current_status',
        'status_description',
        'changed_by',
        'change_reason',
        'status_data',
        'affected_components',
        'status_changed_at',
        'effective_until',
        'duration_minutes',
        'impact_level',
        'attendees_affected',
        'zones_affected',
        'impact_description',
        'public_announcement_made',
        'announcement_text',
        'notification_channels',
        'announced_at',
        'requires_action',
        'recommended_actions',
        'staff_assignments_changed',
        'escalation_triggered',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'status_data' => 'array',
        'affected_components' => 'array',
        'zones_affected' => 'array',
        'notification_channels' => 'array',
        'staff_assignments_changed' => 'array',
        'metadata' => 'array',
        'status_changed_at' => 'datetime',
        'effective_until' => 'datetime',
        'announced_at' => 'datetime',
        'public_announcement_made' => 'boolean',
        'requires_action' => 'boolean',
        'escalation_triggered' => 'boolean',
        'duration_minutes' => 'integer',
        'attendees_affected' => 'integer',
    ];

    protected $dates = [
        'status_changed_at',
        'effective_until',
        'announced_at',
        'created_at',
    ];

    /**
     * Valid status types
     */
    const STATUS_TYPES = [
        'event_status',
        'checkin_status',
        'capacity_status',
        'zone_status',
        'security_status',
        'technical_status',
        'weather_status',
        'emergency_status',
    ];

    /**
     * Valid impact levels
     */
    const IMPACT_LEVELS = [
        'none',
        'low',
        'medium',
        'high',
        'critical',
    ];

    /**
     * Get the event this status log belongs to
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Check if status is currently effective
     */
    public function isCurrentlyEffective(): bool
    {
        if (! $this->effective_until) {
            return true; // No expiration means it's still effective
        }

        return now()->isBefore($this->effective_until);
    }

    /**
     * Check if status has high impact
     */
    public function hasHighImpact(): bool
    {
        return in_array($this->impact_level, ['high', 'critical']);
    }

    /**
     * Check if public announcement was made
     */
    public function hasPublicAnnouncement(): bool
    {
        return $this->public_announcement_made;
    }

    /**
     * Check if escalation was triggered
     */
    public function wasEscalated(): bool
    {
        return $this->escalation_triggered;
    }

    /**
     * Check if action is required
     */
    public function requiresAction(): bool
    {
        return $this->requires_action;
    }

    /**
     * Get duration of status in minutes
     */
    public function getDurationInMinutesAttribute(): ?int
    {
        if ($this->duration_minutes) {
            return $this->duration_minutes;
        }

        // Calculate from next status change or current time
        $nextStatusChange = static::where('event_id', $this->event_id)
            ->where('tenant_id', $this->tenant_id)
            ->where('status_type', $this->status_type)
            ->where('status_changed_at', '>', $this->status_changed_at)
            ->orderBy('status_changed_at')
            ->first();

        $endTime = $nextStatusChange ? $nextStatusChange->status_changed_at : now();

        return $this->status_changed_at->diffInMinutes($endTime);
    }

    /**
     * Get impact level color for UI
     */
    public function getImpactColorAttribute(): string
    {
        return match ($this->impact_level) {
            'critical' => 'red',
            'high' => 'orange',
            'medium' => 'yellow',
            'low' => 'blue',
            'none' => 'gray',
            default => 'gray',
        };
    }

    /**
     * Get status type display name
     */
    public function getStatusTypeDisplayAttribute(): string
    {
        return match ($this->status_type) {
            'event_status' => 'Event Status',
            'checkin_status' => 'Check-in Status',
            'capacity_status' => 'Capacity Status',
            'zone_status' => 'Zone Status',
            'security_status' => 'Security Status',
            'technical_status' => 'Technical Status',
            'weather_status' => 'Weather Status',
            'emergency_status' => 'Emergency Status',
            default => ucfirst(str_replace('_', ' ', $this->status_type)),
        };
    }

    /**
     * Get notification channels as readable list
     */
    public function getNotificationChannelsDisplayAttribute(): string
    {
        if (! $this->notification_channels) {
            return 'None';
        }

        $channels = array_map(function ($channel) {
            return match ($channel) {
                'app' => 'Mobile App',
                'email' => 'Email',
                'sms' => 'SMS',
                'pa_system' => 'PA System',
                'digital_signage' => 'Digital Signage',
                default => ucfirst($channel),
            };
        }, $this->notification_channels);

        return implode(', ', $channels);
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
     * Scope to filter by status type
     */
    public function scopeByStatusType($query, string $statusType)
    {
        return $query->where('status_type', $statusType);
    }

    /**
     * Scope to filter by current status
     */
    public function scopeByCurrentStatus($query, string $currentStatus)
    {
        return $query->where('current_status', $currentStatus);
    }

    /**
     * Scope to filter by impact level
     */
    public function scopeByImpactLevel($query, string $impactLevel)
    {
        return $query->where('impact_level', $impactLevel);
    }

    /**
     * Scope to get high impact status changes only
     */
    public function scopeHighImpact($query)
    {
        return $query->whereIn('impact_level', ['high', 'critical']);
    }

    /**
     * Scope to get status changes with public announcements
     */
    public function scopeWithPublicAnnouncements($query)
    {
        return $query->where('public_announcement_made', true);
    }

    /**
     * Scope to get escalated status changes
     */
    public function scopeEscalated($query)
    {
        return $query->where('escalation_triggered', true);
    }

    /**
     * Scope to get status changes requiring action
     */
    public function scopeRequiringAction($query)
    {
        return $query->where('requires_action', true);
    }

    /**
     * Scope to get currently effective status changes
     */
    public function scopeCurrentlyEffective($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('effective_until')
                ->orWhere('effective_until', '>', now());
        });
    }

    /**
     * Scope to get recent status changes
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('status_changed_at', '>=', now()->subHours($hours));
    }

    /**
     * Scope to order by most recent first
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('status_changed_at', 'desc');
    }
}
