<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualReviewQueue extends Model
{
    use HasFactory;

    protected $table = 'manual_review_queue';

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'customer_id',
        'review_reason',
        'priority',
        'status',
        'risk_factors',
        'risk_score',
        'assigned_reviewer',
        'assigned_at',
        'reviewer_notes',
        'review_actions',
        'reviewed_at',
    ];

    protected $casts = [
        'risk_factors' => 'array',
        'risk_score' => 'decimal:2',
        'assigned_at' => 'datetime',
        'review_actions' => 'array',
        'reviewed_at' => 'datetime',
    ];

    // Review reason constants
    public const REASON_VELOCITY_EXCEEDED = 'velocity_exceeded';

    public const REASON_SUSPICIOUS_PATTERN = 'suspicious_pattern';

    public const REASON_HIGH_RISK_CUSTOMER = 'high_risk_customer';

    public const REASON_MANUAL_FLAG = 'manual_flag';

    public const REASON_IDENTITY_VERIFICATION_REQUIRED = 'identity_verification_required';

    // Priority constants
    public const PRIORITY_LOW = 'low';

    public const PRIORITY_MEDIUM = 'medium';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_URGENT = 'urgent';

    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ESCALATED = 'escalated';

    /**
     * Get the booking being reviewed.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the customer being reviewed.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the assigned reviewer.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_reviewer');
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
     * Scope to filter by priority.
     */
    public function scopeWithPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope to get pending reviews.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to get reviews in progress.
     */
    public function scopeInReview($query)
    {
        return $query->where('status', self::STATUS_IN_REVIEW);
    }

    /**
     * Scope to get completed reviews.
     */
    public function scopeCompleted($query)
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_REJECTED]);
    }

    /**
     * Scope to get unassigned reviews.
     */
    public function scopeUnassigned($query)
    {
        return $query->whereNull('assigned_reviewer');
    }

    /**
     * Scope to filter by assigned reviewer.
     */
    public function scopeAssignedTo($query, int $reviewerId)
    {
        return $query->where('assigned_reviewer', $reviewerId);
    }

    /**
     * Scope to order by priority and creation date.
     */
    public function scopeByPriority($query)
    {
        $priorityOrder = [
            self::PRIORITY_URGENT => 4,
            self::PRIORITY_HIGH => 3,
            self::PRIORITY_MEDIUM => 2,
            self::PRIORITY_LOW => 1,
        ];

        return $query->orderByRaw("CASE 
            WHEN priority = '".self::PRIORITY_URGENT."' THEN 4
            WHEN priority = '".self::PRIORITY_HIGH."' THEN 3
            WHEN priority = '".self::PRIORITY_MEDIUM."' THEN 2
            WHEN priority = '".self::PRIORITY_LOW."' THEN 1
            ELSE 0 END DESC")
            ->orderBy('created_at', 'asc');
    }

    /**
     * Scope to get high-risk reviews.
     */
    public function scopeHighRisk($query, float $threshold = 70.0)
    {
        return $query->where('risk_score', '>=', $threshold);
    }

    /**
     * Check if the review is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the review is in progress.
     */
    public function isInReview(): bool
    {
        return $this->status === self::STATUS_IN_REVIEW;
    }

    /**
     * Check if the review is completed.
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED]);
    }

    /**
     * Check if the review is high priority.
     */
    public function isHighPriority(): bool
    {
        return in_array($this->priority, [self::PRIORITY_HIGH, self::PRIORITY_URGENT]);
    }

    /**
     * Assign the review to a reviewer.
     */
    public function assignTo(int $reviewerId): void
    {
        $this->update([
            'assigned_reviewer' => $reviewerId,
            'assigned_at' => now(),
            'status' => self::STATUS_IN_REVIEW,
        ]);
    }

    /**
     * Approve the booking.
     */
    public function approve(string $notes = '', array $actions = []): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'reviewer_notes' => $notes,
            'review_actions' => $actions,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Reject the booking.
     */
    public function reject(string $notes, array $actions = []): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'reviewer_notes' => $notes,
            'review_actions' => $actions,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Escalate the review.
     */
    public function escalate(string $notes = ''): void
    {
        $this->update([
            'status' => self::STATUS_ESCALATED,
            'reviewer_notes' => $notes,
            'priority' => self::PRIORITY_URGENT,
        ]);
    }

    /**
     * Get the priority color for UI display.
     */
    public function getPriorityColor(): string
    {
        switch ($this->priority) {
            case self::PRIORITY_URGENT:
                return 'red';
            case self::PRIORITY_HIGH:
                return 'orange';
            case self::PRIORITY_MEDIUM:
                return 'yellow';
            case self::PRIORITY_LOW:
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
            case self::STATUS_PENDING:
                return 'yellow';
            case self::STATUS_IN_REVIEW:
                return 'blue';
            case self::STATUS_APPROVED:
                return 'green';
            case self::STATUS_REJECTED:
                return 'red';
            case self::STATUS_ESCALATED:
                return 'purple';
            default:
                return 'gray';
        }
    }

    /**
     * Get the time since the review was created.
     */
    public function getAgeInHours(): int
    {
        return $this->created_at->diffInHours(now());
    }

    /**
     * Get the time since the review was assigned.
     */
    public function getAssignedAgeInHours(): ?int
    {
        return $this->assigned_at ? $this->assigned_at->diffInHours(now()) : null;
    }

    /**
     * Check if the review is overdue.
     */
    public function isOverdue(): bool
    {
        $slaHours = match ($this->priority) {
            self::PRIORITY_URGENT => 1,
            self::PRIORITY_HIGH => 4,
            self::PRIORITY_MEDIUM => 24,
            self::PRIORITY_LOW => 72,
            default => 24,
        };

        return $this->getAgeInHours() > $slaHours;
    }

    /**
     * Get the risk level based on risk score.
     */
    public function getRiskLevel(): string
    {
        if ($this->risk_score >= 90) {
            return 'critical';
        } elseif ($this->risk_score >= 70) {
            return 'high';
        } elseif ($this->risk_score >= 40) {
            return 'medium';
        }

        return 'low';
    }
}
