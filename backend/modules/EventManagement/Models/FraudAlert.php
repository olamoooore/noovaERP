<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraudAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'fraud_detection_rule_id',
        'alert_type',
        'severity',
        'status',
        'entity_type',
        'entity_id',
        'description',
        'evidence',
        'actions_taken',
        'assigned_to',
        'resolution_notes',
        'resolved_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'actions_taken' => 'array',
        'resolved_at' => 'datetime',
    ];

    // Severity constants
    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    // Status constants
    public const STATUS_OPEN = 'open';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_FALSE_POSITIVE = 'false_positive';

    // Entity type constants
    public const ENTITY_CUSTOMER = 'customer';

    public const ENTITY_IP_ADDRESS = 'ip_address';

    public const ENTITY_BOOKING = 'booking';

    public const ENTITY_SESSION = 'session';

    /**
     * Get the fraud detection rule that generated this alert.
     */
    public function fraudDetectionRule(): BelongsTo
    {
        return $this->belongsTo(FraudDetectionRule::class);
    }

    /**
     * Get the user assigned to this alert.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Scope to filter by tenant.
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by severity.
     */
    public function scopeWithSeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Scope to get open alerts.
     */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_INVESTIGATING]);
    }

    /**
     * Scope to get resolved alerts.
     */
    public function scopeResolved($query)
    {
        return $query->whereIn('status', [self::STATUS_RESOLVED, self::STATUS_FALSE_POSITIVE]);
    }

    /**
     * Scope to get unassigned alerts.
     */
    public function scopeUnassigned($query)
    {
        return $query->whereNull('assigned_to');
    }

    /**
     * Scope to filter by entity type and ID.
     */
    public function scopeForEntity($query, string $entityType, string $entityId)
    {
        return $query->where('entity_type', $entityType)
            ->where('entity_id', $entityId);
    }

    /**
     * Scope to get recent alerts.
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Check if the alert is currently open.
     */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_INVESTIGATING]);
    }

    /**
     * Check if the alert is resolved.
     */
    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_FALSE_POSITIVE]);
    }

    /**
     * Check if the alert is high priority.
     */
    public function isHighPriority(): bool
    {
        return in_array($this->severity, [self::SEVERITY_HIGH, self::SEVERITY_CRITICAL]);
    }

    /**
     * Assign the alert to a user.
     */
    public function assignTo(int $userId): void
    {
        $this->update([
            'assigned_to' => $userId,
            'status' => self::STATUS_INVESTIGATING,
        ]);
    }

    /**
     * Resolve the alert.
     */
    public function resolve(string $resolutionNotes, bool $isFalsePositive = false): void
    {
        $this->update([
            'status' => $isFalsePositive ? self::STATUS_FALSE_POSITIVE : self::STATUS_RESOLVED,
            'resolution_notes' => $resolutionNotes,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Record an action taken on this alert.
     */
    public function recordAction(string $action, array $details = []): void
    {
        $actions = $this->actions_taken ?? [];
        $actions[] = [
            'action' => $action,
            'details' => $details,
            'timestamp' => now()->toISOString(),
        ];

        $this->update(['actions_taken' => $actions]);
    }

    /**
     * Get the severity color for UI display.
     */
    public function getSeverityColor(): string
    {
        switch ($this->severity) {
            case self::SEVERITY_CRITICAL:
                return 'red';
            case self::SEVERITY_HIGH:
                return 'orange';
            case self::SEVERITY_MEDIUM:
                return 'yellow';
            case self::SEVERITY_LOW:
            default:
                return 'blue';
        }
    }

    /**
     * Get the status color for UI display.
     */
    public function getStatusColor(): string
    {
        switch ($this->status) {
            case self::STATUS_OPEN:
                return 'red';
            case self::STATUS_INVESTIGATING:
                return 'yellow';
            case self::STATUS_RESOLVED:
                return 'green';
            case self::STATUS_FALSE_POSITIVE:
                return 'gray';
            default:
                return 'blue';
        }
    }

    /**
     * Get the time since the alert was created.
     */
    public function getAgeInHours(): int
    {
        return $this->created_at->diffInHours(now());
    }

    /**
     * Get the related entity (customer, booking, etc.).
     */
    public function getRelatedEntity()
    {
        switch ($this->entity_type) {
            case self::ENTITY_CUSTOMER:
                return Customer::find($this->entity_id);
            case self::ENTITY_BOOKING:
                return Booking::find($this->entity_id);
            default:
                return null;
        }
    }
}
