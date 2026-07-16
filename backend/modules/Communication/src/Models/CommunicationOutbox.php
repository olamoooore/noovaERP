<?php

namespace Modules\Communication\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CommunicationOutbox — Async delivery queue.
 *
 * Every message-to-channel delivery attempt is tracked here.
 * Messages are queued, sent in batches, with automatic retry on failure.
 * Equivalent to Odoo's mail.mail.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property int $message_id
 * @property int|null $recipient_user_id
 * @property int|null $recipient_customer_id
 * @property string|null $recipient_email
 * @property string|null $recipient_phone
 * @property string|null $recipient_device_token
 * @property string $channel
 * @property string|null $provider
 * @property string $status
 * @property int $retry_count
 * @property int $max_retries
 * @property string|null $error_message
 * @property array|null $provider_response
 * @property string|null $external_id
 */
class CommunicationOutbox extends Model
{
    protected $table = 'communication_outbox';

    const STATUS_PENDING = 'pending';

    const STATUS_PROCESSING = 'processing';

    const STATUS_SENT = 'sent';

    const STATUS_DELIVERED = 'delivered';

    const STATUS_FAILED = 'failed';

    const STATUS_BOUNCED = 'bounced';

    const STATUS_CANCELLED = 'cancelled';

    const CHANNEL_EMAIL = 'email';

    const CHANNEL_IN_APP = 'in_app';

    const CHANNEL_SMS = 'sms';

    const CHANNEL_WHATSAPP = 'whatsapp';

    const CHANNEL_PUSH = 'push';

    const CHANNEL_SLACK = 'slack';

    protected $fillable = [
        'tenant_id',
        'message_id',
        'recipient_user_id',
        'recipient_customer_id',
        'recipient_email',
        'recipient_phone',
        'recipient_device_token',
        'channel',
        'provider',
        'status',
        'retry_count',
        'max_retries',
        'error_message',
        'provider_response',
        'external_id',
        'scheduled_at',
        'sent_at',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'failed_at',
        'next_retry_at',
    ];

    protected $casts = [
        'provider_response' => 'array',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'failed_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────

    public function message(): BelongsTo
    {
        return $this->belongsTo(CommunicationMessage::class, 'message_id');
    }

    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'recipient_user_id');
    }

    public function recipientCustomer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Customer::class, 'recipient_customer_id');
    }

    // ── Scopes ─────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeReadyToSend($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', now());
            });
    }

    public function scopeRetryable($query)
    {
        return $query->where('status', self::STATUS_FAILED)
            ->whereColumn('retry_count', '<', 'max_retries')
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            });
    }

    public function scopeForChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    // ── Helpers ─────────────────────────────────────────

    public function canRetry(): bool
    {
        return $this->retry_count < $this->max_retries;
    }

    public function markAsSent(?string $externalId = null): void
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
            'external_id' => $externalId,
            'error_message' => null,
        ]);
    }

    public function markAsDelivered(): void
    {
        $this->update([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
    }

    public function markAsFailed(string $error, bool $scheduleRetry = true): void
    {
        $data = [
            'status' => self::STATUS_FAILED,
            'failed_at' => now(),
            'error_message' => $error,
            'retry_count' => $this->retry_count + 1,
        ];

        if ($scheduleRetry && $this->canRetry()) {
            $delay = $this->calculateRetryDelay();
            $data['next_retry_at'] = now()->addSeconds($delay);
            $data['status'] = self::STATUS_PENDING; // Re-queue for retry
        }

        $this->update($data);
    }

    /**
     * Calculate retry delay using exponential backoff.
     */
    protected function calculateRetryDelay(): int
    {
        $baseDelay = config('communication.outbox.retry_delay_seconds', 60);
        $strategy = config('communication.outbox.retry_backoff', 'exponential');

        if ($strategy === 'exponential') {
            return $baseDelay * pow(2, $this->retry_count);
        }

        return $baseDelay * ($this->retry_count + 1);
    }
}
