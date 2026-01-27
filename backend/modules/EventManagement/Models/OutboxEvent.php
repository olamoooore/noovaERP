<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * OutboxEvent Model
 * 
 * Represents events in the transactional outbox pattern for exactly-once processing.
 * 
 * @property int $id
 * @property string $aggregate_id
 * @property string $aggregate_type
 * @property string $event_type
 * @property array $event_data
 * @property string $idempotency_key
 * @property string $status
 * @property int $retry_count
 * @property int $max_retries
 * @property \Carbon\Carbon|null $next_retry_at
 * @property string|null $error_message
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon|null $processed_at
 */
class OutboxEvent extends Model
{
    protected $table = 'outbox_events';
    
    public $timestamps = false; // Custom timestamp handling
    
    protected $fillable = [
        'aggregate_id',
        'aggregate_type',
        'event_type',
        'event_data',
        'idempotency_key',
        'status',
        'retry_count',
        'max_retries',
        'next_retry_at',
        'error_message',
        'metadata'
    ];

    protected $casts = [
        'event_data' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'processed_at' => 'datetime',
        'next_retry_at' => 'datetime'
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    // Event type constants
    public const EVENT_BOOKING_CREATED = 'booking.created';
    public const EVENT_PAYMENT_CONFIRMED = 'payment.confirmed';
    public const EVENT_TICKET_ISSUED = 'ticket.issued';
    public const EVENT_BOOKING_CANCELLED = 'booking.cancelled';
    public const EVENT_FRAUD_DETECTED = 'fraud.detected';
    public const EVENT_TIER_UPGRADED = 'tier.upgraded';
    public const EVENT_ANALYTICS_UPDATE = 'analytics.update';
    public const EVENT_NOTIFICATION_SEND = 'notification.send';

    /**
     * Relationship to consumer processing records
     */
    public function consumers(): HasMany
    {
        return $this->hasMany(OutboxEventConsumer::class);
    }

    /**
     * Relationship to processing logs
     */
    public function processingLogs(): HasMany
    {
        return $this->hasMany(OutboxProcessingLog::class);
    }

    /**
     * Check if event is pending processing
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if event is currently being processed
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if event has been completed
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if event has failed
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if event can be retried
     */
    public function canRetry(): bool
    {
        return $this->isFailed() && 
               $this->retry_count < $this->max_retries &&
               ($this->next_retry_at === null || $this->next_retry_at <= now());
    }

    /**
     * Mark event as processing
     */
    public function markAsProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'processed_at' => null
        ]);
    }

    /**
     * Mark event as completed
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'processed_at' => now(),
            'error_message' => null
        ]);
    }

    /**
     * Mark event as failed and schedule retry
     */
    public function markAsFailed(string $errorMessage, ?int $retryDelayMinutes = null): void
    {
        $retryDelayMinutes = $retryDelayMinutes ?? $this->calculateRetryDelay();
        
        $this->update([
            'status' => self::STATUS_FAILED,
            'retry_count' => $this->retry_count + 1,
            'error_message' => $errorMessage,
            'next_retry_at' => $this->retry_count < $this->max_retries 
                ? now()->addMinutes($retryDelayMinutes) 
                : null
        ]);
    }

    /**
     * Calculate exponential backoff retry delay
     */
    private function calculateRetryDelay(): int
    {
        // Exponential backoff: 2^retry_count minutes, max 60 minutes
        return min(pow(2, $this->retry_count), 60);
    }

    /**
     * Get event data value by key
     */
    public function getEventDataValue(string $key): mixed
    {
        return $this->event_data[$key] ?? null;
    }

    /**
     * Get metadata value by key
     */
    public function getMetadataValue(string $key): mixed
    {
        return $this->metadata[$key] ?? null;
    }

    /**
     * Scope for pending events ready for processing
     */
    public function scopeReadyForProcessing($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->orWhere(function ($q) {
                $q->where('status', self::STATUS_FAILED)
                  ->where('retry_count', '<', DB::raw('max_retries'))
                  ->where(function ($retryQuery) {
                      $retryQuery->whereNull('next_retry_at')
                                 ->orWhere('next_retry_at', '<=', now());
                  });
            });
    }

    /**
     * Scope for specific event type
     */
    public function scopeOfType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Scope for specific aggregate
     */
    public function scopeForAggregate($query, string $aggregateType, string $aggregateId)
    {
        return $query->where('aggregate_type', $aggregateType)
                    ->where('aggregate_id', $aggregateId);
    }

    /**
     * Scope for failed events
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope for events older than specified minutes
     */
    public function scopeOlderThan($query, int $minutes)
    {
        return $query->where('created_at', '<', now()->subMinutes($minutes));
    }

    /**
     * Create event with automatic idempotency key generation
     */
    public static function createEvent(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $eventData,
        ?string $idempotencyKey = null,
        ?array $metadata = null
    ): self {
        $idempotencyKey = $idempotencyKey ?? self::generateIdempotencyKey($aggregateType, $aggregateId, $eventType);
        
        return self::create([
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'event_data' => $eventData,
            'idempotency_key' => $idempotencyKey,
            'metadata' => $metadata ?? []
        ]);
    }

    /**
     * Generate deterministic idempotency key
     */
    public static function generateIdempotencyKey(string $aggregateType, string $aggregateId, string $eventType): string
    {
        return hash('sha256', $aggregateType . ':' . $aggregateId . ':' . $eventType . ':' . now()->timestamp);
    }
}