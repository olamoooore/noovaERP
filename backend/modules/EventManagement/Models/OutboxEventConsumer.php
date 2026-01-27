<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OutboxEventConsumer Model
 * 
 * Tracks processing status for each consumer of outbox events.
 * 
 * @property int $id
 * @property int $outbox_event_id
 * @property string $consumer_name
 * @property string $status
 * @property int $retry_count
 * @property int $max_retries
 * @property \Carbon\Carbon|null $next_retry_at
 * @property string|null $error_message
 * @property array|null $processing_metadata
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 * @property \Carbon\Carbon $created_at
 */
class OutboxEventConsumer extends Model
{
    protected $table = 'outbox_event_consumers';
    
    public $timestamps = false; // Custom timestamp handling
    
    protected $fillable = [
        'outbox_event_id',
        'consumer_name',
        'status',
        'retry_count',
        'max_retries',
        'next_retry_at',
        'error_message',
        'processing_metadata',
        'started_at',
        'completed_at'
    ];

    protected $casts = [
        'processing_metadata' => 'array',
        'created_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'next_retry_at' => 'datetime'
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    // Consumer name constants
    public const CONSUMER_NOTIFICATION = 'notification_service';
    public const CONSUMER_ANALYTICS = 'analytics_service';
    public const CONSUMER_ACCOUNTING = 'accounting_service';
    public const CONSUMER_LOYALTY = 'loyalty_service';
    public const CONSUMER_FRAUD_DETECTION = 'fraud_detection_service';

    /**
     * Relationship to the outbox event
     */
    public function outboxEvent(): BelongsTo
    {
        return $this->belongsTo(OutboxEvent::class);
    }

    /**
     * Check if consumer processing is pending
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if consumer is currently processing
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if consumer has completed processing
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if consumer processing has failed
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if consumer can retry processing
     */
    public function canRetry(): bool
    {
        return $this->isFailed() && 
               $this->retry_count < $this->max_retries &&
               ($this->next_retry_at === null || $this->next_retry_at <= now());
    }

    /**
     * Mark consumer as processing
     */
    public function markAsProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'started_at' => now(),
            'completed_at' => null
        ]);
    }

    /**
     * Mark consumer as completed
     */
    public function markAsCompleted(?array $metadata = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
            'error_message' => null,
            'processing_metadata' => $metadata
        ]);
    }

    /**
     * Mark consumer as failed and schedule retry
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
        // Exponential backoff: 2^retry_count minutes, max 30 minutes
        return min(pow(2, $this->retry_count), 30);
    }

    /**
     * Get processing duration in seconds
     */
    public function getProcessingDuration(): ?int
    {
        if (!$this->started_at) {
            return null;
        }
        
        $endTime = $this->completed_at ?? now();
        return $this->started_at->diffInSeconds($endTime);
    }

    /**
     * Get processing metadata value by key
     */
    public function getMetadataValue(string $key): mixed
    {
        return $this->processing_metadata[$key] ?? null;
    }

    /**
     * Set processing metadata value
     */
    public function setMetadataValue(string $key, mixed $value): void
    {
        $metadata = $this->processing_metadata ?? [];
        $metadata[$key] = $value;
        $this->update(['processing_metadata' => $metadata]);
    }

    /**
     * Scope for specific consumer
     */
    public function scopeForConsumer($query, string $consumerName)
    {
        return $query->where('consumer_name', $consumerName);
    }

    /**
     * Scope for ready to process
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
     * Scope for failed consumers
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope for completed consumers
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope for processing longer than specified minutes
     */
    public function scopeProcessingLongerThan($query, int $minutes)
    {
        return $query->where('status', self::STATUS_PROCESSING)
                    ->where('started_at', '<', now()->subMinutes($minutes));
    }

    /**
     * Create consumer record for an event
     */
    public static function createForEvent(int $outboxEventId, string $consumerName, int $maxRetries = 3): self
    {
        return self::create([
            'outbox_event_id' => $outboxEventId,
            'consumer_name' => $consumerName,
            'max_retries' => $maxRetries
        ]);
    }
}