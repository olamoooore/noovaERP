<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CheckInCounter Model
 *
 * Represents check-in points and queue management for event operations.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $event_id
 * @property int|null $zone_id
 * @property string $counter_code
 * @property string $counter_name
 * @property string|null $location_description
 * @property array|null $coordinates
 * @property string $counter_type
 * @property array|null $supported_ticket_types
 * @property bool $supports_group_checkin
 * @property int|null $max_group_size
 * @property int $current_queue_length
 * @property int|null $max_queue_length
 * @property float|null $average_processing_time
 * @property int $total_processed
 * @property \Carbon\Carbon|null $last_activity_at
 * @property string|null $assigned_staff_id
 * @property int $staff_count
 * @property bool $requires_supervisor
 * @property string $status
 * @property \Carbon\Carbon|null $opened_at
 * @property \Carbon\Carbon|null $closed_at
 * @property string|null $status_notes
 * @property int $checkins_per_hour
 * @property float|null $efficiency_rating
 * @property array|null $performance_metrics
 * @property array|null $equipment_list
 * @property bool $has_technical_issues
 * @property string|null $technical_notes
 * @property array|null $metadata
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * @property string|null $created_by
 * @property string|null $updated_by
 */
class CheckInCounter extends Model
{
    use SoftDeletes;

    protected $table = 'check_in_counters';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'zone_id',
        'counter_code',
        'counter_name',
        'location_description',
        'coordinates',
        'counter_type',
        'supported_ticket_types',
        'supports_group_checkin',
        'max_group_size',
        'current_queue_length',
        'max_queue_length',
        'average_processing_time',
        'total_processed',
        'last_activity_at',
        'assigned_staff_id',
        'staff_count',
        'requires_supervisor',
        'status',
        'opened_at',
        'closed_at',
        'status_notes',
        'checkins_per_hour',
        'efficiency_rating',
        'performance_metrics',
        'equipment_list',
        'has_technical_issues',
        'technical_notes',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'coordinates' => 'array',
        'supported_ticket_types' => 'array',
        'performance_metrics' => 'array',
        'equipment_list' => 'array',
        'metadata' => 'array',
        'last_activity_at' => 'datetime',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'supports_group_checkin' => 'boolean',
        'requires_supervisor' => 'boolean',
        'has_technical_issues' => 'boolean',
        'current_queue_length' => 'integer',
        'max_queue_length' => 'integer',
        'total_processed' => 'integer',
        'staff_count' => 'integer',
        'checkins_per_hour' => 'integer',
        'max_group_size' => 'integer',
        'average_processing_time' => 'decimal:2',
        'efficiency_rating' => 'decimal:2',
    ];

    protected $dates = [
        'last_activity_at',
        'opened_at',
        'closed_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Valid counter types
     */
    const COUNTER_TYPES = [
        'general',
        'vip',
        'group',
        'accessibility',
        'express',
        'will_call',
    ];

    /**
     * Valid counter statuses
     */
    const STATUSES = [
        'closed',
        'opening',
        'active',
        'busy',
        'break',
        'closing',
        'maintenance',
    ];

    /**
     * Get the event this counter belongs to
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the operational zone this counter is in
     */
    public function operationalZone(): BelongsTo
    {
        return $this->belongsTo(OperationalZone::class, 'zone_id');
    }

    /**
     * Get incidents at this counter
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(IncidentLog::class, 'counter_id');
    }

    /**
     * Check if counter is currently active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if counter is busy (long queue)
     */
    public function isBusy(): bool
    {
        return $this->status === 'busy' ||
               ($this->max_queue_length && $this->current_queue_length >= $this->max_queue_length * 0.8);
    }

    /**
     * Check if counter has technical issues
     */
    public function hasTechnicalIssues(): bool
    {
        return $this->has_technical_issues;
    }

    /**
     * Check if counter supports a specific ticket type
     */
    public function supportsTicketType(string $ticketType): bool
    {
        $supportedTypes = $this->supported_ticket_types ?? [];

        return empty($supportedTypes) || in_array($ticketType, $supportedTypes);
    }

    /**
     * Update queue length and processing metrics
     */
    public function updateQueue(int $queueChange): void
    {
        $this->current_queue_length = max(0, $this->current_queue_length + $queueChange);
        $this->last_activity_at = now();

        // If queue decreased, someone was processed
        if ($queueChange < 0) {
            $this->total_processed += abs($queueChange);
            $this->updateProcessingMetrics();
        }

        // Update status based on queue length
        if ($this->max_queue_length && $this->current_queue_length >= $this->max_queue_length) {
            $this->status = 'busy';
        } elseif ($this->current_queue_length > 0 && $this->status === 'active') {
            // Keep active status
        } elseif ($this->current_queue_length === 0 && $this->status === 'busy') {
            $this->status = 'active';
        }

        $this->save();
    }

    /**
     * Process a check-in (decrease queue by 1)
     */
    public function processCheckIn(): void
    {
        $this->updateQueue(-1);
    }

    /**
     * Add person to queue
     */
    public function addToQueue(int $count = 1): void
    {
        $this->updateQueue($count);
    }

    /**
     * Update processing metrics based on recent activity
     */
    private function updateProcessingMetrics(): void
    {
        // Calculate hourly processing rate
        $hourStart = now()->startOfHour();
        $hourlyProcessed = $this->total_processed; // This would need more sophisticated tracking in real implementation

        $this->checkins_per_hour = $hourlyProcessed;

        // Update average processing time (simplified calculation)
        if ($this->total_processed > 0) {
            $totalMinutes = $this->opened_at ? $this->opened_at->diffInMinutes(now()) : 60;
            $this->average_processing_time = $totalMinutes / $this->total_processed;
        }

        // Calculate efficiency rating (simplified)
        $expectedRate = 30; // Expected check-ins per hour
        if ($this->checkins_per_hour > 0) {
            $this->efficiency_rating = min(5.0, ($this->checkins_per_hour / $expectedRate) * 5.0);
        }
    }

    /**
     * Get estimated wait time in minutes
     */
    public function getEstimatedWaitTimeAttribute(): int
    {
        if ($this->current_queue_length === 0) {
            return 0;
        }

        $processingTime = $this->average_processing_time ?? 2.0; // Default 2 minutes per person

        return (int) ceil($this->current_queue_length * $processingTime);
    }

    /**
     * Get queue status description
     */
    public function getQueueStatusAttribute(): string
    {
        if ($this->current_queue_length === 0) {
            return 'No Queue';
        } elseif ($this->current_queue_length <= 5) {
            return 'Short Queue';
        } elseif ($this->current_queue_length <= 15) {
            return 'Moderate Queue';
        } else {
            return 'Long Queue';
        }
    }

    /**
     * Get performance status
     */
    public function getPerformanceStatusAttribute(): string
    {
        if ($this->has_technical_issues) {
            return 'Technical Issues';
        }

        if ($this->efficiency_rating >= 4.0) {
            return 'Excellent';
        } elseif ($this->efficiency_rating >= 3.0) {
            return 'Good';
        } elseif ($this->efficiency_rating >= 2.0) {
            return 'Average';
        } else {
            return 'Below Average';
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
     * Scope to filter by counter type
     */
    public function scopeByType($query, string $counterType)
    {
        return $query->where('counter_type', $counterType);
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get active counters only
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get counters with queues
     */
    public function scopeWithQueue($query)
    {
        return $query->where('current_queue_length', '>', 0);
    }

    /**
     * Scope to get counters with technical issues
     */
    public function scopeWithTechnicalIssues($query)
    {
        return $query->where('has_technical_issues', true);
    }

    /**
     * Scope to get busy counters
     */
    public function scopeBusy($query)
    {
        return $query->where('status', 'busy')
            ->orWhere('current_queue_length', '>', 10);
    }
}
