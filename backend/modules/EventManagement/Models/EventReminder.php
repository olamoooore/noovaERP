<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\EventManagement\Traits\HasTenantScope;
use Carbon\Carbon;

/**
 * EventReminder Model
 * 
 * Manages scheduled notifications for customers about events.
 * 
 * @property int $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property int $event_id
 * @property int|null $booking_id
 * @property string $reminder_type
 * @property \Carbon\Carbon $scheduled_at
 * @property \Carbon\Carbon|null $sent_at
 * @property string $status
 * @property string $channel
 * @property string $recipient_address
 * @property string|null $subject
 * @property string $message
 * @property array|null $template_data
 * @property array|null $delivery_metadata
 * @property int $retry_count
 * @property \Carbon\Carbon|null $last_retry_at
 * @property string|null $failure_reason
 */
class EventReminder extends Model
{
    use SoftDeletes, HasTenantScope;

    protected $table = 'event_reminders';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'event_id',
        'booking_id',
        'reminder_type',
        'scheduled_at',
        'sent_at',
        'status',
        'channel',
        'recipient_address',
        'subject',
        'message',
        'template_data',
        'delivery_metadata',
        'retry_count',
        'last_retry_at',
        'failure_reason',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'last_retry_at' => 'datetime',
        'template_data' => 'array',
        'delivery_metadata' => 'array',
        'retry_count' => 'integer',
    ];

    protected $dates = [
        'scheduled_at',
        'sent_at',
        'last_retry_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Get the event associated with this reminder
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the booking associated with this reminder
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Scope for pending reminders
     */
    public function scopePending($query)
    {
        return $query->where('status', 'scheduled')
                    ->where('scheduled_at', '<=', now());
    }

    /**
     * Scope for specific reminder type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('reminder_type', $type);
    }

    /**
     * Scope for specific channel
     */
    public function scopeByChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope for specific customer
     */
    public function scopeForCustomer($query, string $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Scope for failed reminders that can be retried
     */
    public function scopeRetryable($query, int $maxRetries = 3)
    {
        return $query->where('status', 'failed')
                    ->where('retry_count', '<', $maxRetries)
                    ->where(function ($q) {
                        $q->whereNull('last_retry_at')
                          ->orWhere('last_retry_at', '<', now()->subHours(1));
                    });
    }

    /**
     * Mark reminder as sent
     */
    public function markAsSent(?array $deliveryMetadata = null): bool
    {
        return $this->update([
            'status' => 'sent',
            'sent_at' => now(),
            'delivery_metadata' => $deliveryMetadata,
        ]);
    }

    /**
     * Mark reminder as failed
     */
    public function markAsFailed(string $reason, ?array $deliveryMetadata = null): bool
    {
        return $this->update([
            'status' => 'failed',
            'failure_reason' => $reason,
            'retry_count' => $this->retry_count + 1,
            'last_retry_at' => now(),
            'delivery_metadata' => $deliveryMetadata,
        ]);
    }

    /**
     * Cancel reminder
     */
    public function cancel(): bool
    {
        return $this->update(['status' => 'cancelled']);
    }

    /**
     * Check if reminder can be retried
     */
    public function canRetry(int $maxRetries = 3): bool
    {
        return $this->status === 'failed' 
            && $this->retry_count < $maxRetries
            && ($this->last_retry_at === null || $this->last_retry_at->lt(now()->subHours(1)));
    }

    /**
     * Get reminder types
     */
    public static function getReminderTypes(): array
    {
        return [
            'event_announcement' => 'Event Announcement',
            'booking_confirmation' => 'Booking Confirmation',
            'event_reminder' => 'Event Reminder',
            'check_in_reminder' => 'Check-in Reminder',
            'post_event_feedback' => 'Post-Event Feedback',
            'ticket_download' => 'Ticket Download',
            'event_update' => 'Event Update',
            'cancellation_notice' => 'Cancellation Notice',
            'refund_notice' => 'Refund Notice',
        ];
    }

    /**
     * Get available channels
     */
    public static function getChannels(): array
    {
        return [
            'email' => 'Email',
            'sms' => 'SMS',
            'push' => 'Push Notification',
            'in_app' => 'In-App Notification',
        ];
    }

    /**
     * Create a reminder
     */
    public static function createReminder(
        string $tenantId,
        string $customerId,
        int $eventId,
        string $reminderType,
        Carbon $scheduledAt,
        string $channel,
        string $recipientAddress,
        string $message,
        ?string $subject = null,
        ?int $bookingId = null,
        ?array $templateData = null
    ): self {
        return self::create([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'event_id' => $eventId,
            'booking_id' => $bookingId,
            'reminder_type' => $reminderType,
            'scheduled_at' => $scheduledAt,
            'channel' => $channel,
            'recipient_address' => $recipientAddress,
            'subject' => $subject,
            'message' => $message,
            'template_data' => $templateData,
            'status' => 'scheduled',
        ]);
    }
}