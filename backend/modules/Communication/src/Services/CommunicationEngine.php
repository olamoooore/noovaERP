<?php

namespace Modules\Communication\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Communication\Models\CommunicationMessage;
use Modules\Communication\Models\CommunicationSubscriber;
use Modules\Communication\Models\CommunicationOutbox;
use Modules\Communication\Models\CommunicationPreference;
use Modules\Communication\Models\CommunicationChannelLog;
use Modules\Communication\Jobs\ProcessOutboxBatch;
use Modules\Communication\Jobs\ProcessDigestQueue;

/**
 * CommunicationEngine — The central communication bus for NoovaERP.
 *
 * This is the single entry point for ALL system communications.
 * Inspired by Odoo's mail.message architecture.
 *
 * Flow:
 *   Business Event → CommunicationEngine → Message Store → Channel Router → Delivery
 *
 * NEVER send mail directly from business logic. Always go through this engine.
 *
 * Usage:
 *   app(CommunicationEngine::class)->notify(
 *       model: 'invoice',
 *       recordId: $invoice->id,
 *       subtype: 'invoice.sent',
 *       subject: 'Invoice Sent',
 *       body: '<p>Your invoice is ready.</p>',
 *       authorId: auth()->id(),
 *   );
 */
class CommunicationEngine
{
    public function __construct(
        protected ChannelRouter $channelRouter,
        protected TemplateRenderer $templateRenderer,
    ) {}

    // ════════════════════════════════════════════════════════
    //  1. CREATE MESSAGES
    // ════════════════════════════════════════════════════════

    /**
     * Send a notification to all subscribers of a record.
     *
     * This is the primary method for event-driven notifications.
     */
    public function notify(
        string $model,
        int $recordId,
        string $subtype,
        string $subject,
        string $body,
        ?int $authorId = null,
        string $authorType = 'system',
        string $messageType = CommunicationMessage::TYPE_NOTIFICATION,
        ?string $templateKey = null,
        array $templateVariables = [],
        ?string $tenantId = null,
        array $metadata = [],
        ?\Carbon\Carbon $scheduledAt = null,
    ): CommunicationMessage {
        // If template key is provided, render it
        if ($templateKey) {
            $rendered = $this->templateRenderer->render($templateKey, $templateVariables, 'en', $tenantId);
            if ($rendered) {
                $subject = $rendered['subject'] ?? $subject;
                $body = $rendered['body_html'] ?? $body;
            }
        }

        // Create the message
        $message = CommunicationMessage::create([
            'tenant_id' => $tenantId,
            'model' => $model,
            'record_id' => $recordId,
            'message_type' => $messageType,
            'subtype' => $subtype,
            'subject' => $subject,
            'body' => $body,
            'body_plain' => strip_tags($body),
            'metadata' => $metadata,
            'author_id' => $authorId,
            'author_type' => $authorType,
            'status' => CommunicationMessage::STATUS_QUEUED,
            'scheduled_at' => $scheduledAt,
        ]);

        // Get all active subscribers for this record
        $subscribers = CommunicationSubscriber::forRecord($model, $recordId)
            ->active()
            ->when($tenantId, fn($q) => $q->forTenant($tenantId))
            ->get();

        // Route to channels and create outbox items
        $this->routeToSubscribers($message, $subscribers, $subtype);

        // Dispatch async processing
        ProcessOutboxBatch::dispatch($message->id);

        Log::info('Communication: Notification created', [
            'message_id' => $message->id,
            'model' => $model,
            'record_id' => $recordId,
            'subtype' => $subtype,
            'subscribers' => $subscribers->count(),
        ]);

        return $message;
    }

    /**
     * Send a direct message to specific users/customers (no subscription needed).
     */
    public function sendDirect(
        string $subject,
        string $body,
        array $recipientUserIds = [],
        array $recipientCustomerIds = [],
        array $recipientEmails = [],
        array $channels = ['email', 'in_app'],
        string $messageType = CommunicationMessage::TYPE_EMAIL,
        ?string $subtype = null,
        ?string $model = null,
        ?int $recordId = null,
        ?int $authorId = null,
        ?string $tenantId = null,
        ?string $templateKey = null,
        array $templateVariables = [],
        array $metadata = [],
    ): CommunicationMessage {
        // Render template if provided
        if ($templateKey) {
            $rendered = $this->templateRenderer->render($templateKey, $templateVariables, 'en', $tenantId);
            if ($rendered) {
                $subject = $rendered['subject'] ?? $subject;
                $body = $rendered['body_html'] ?? $body;
            }
        }

        $message = CommunicationMessage::create([
            'tenant_id' => $tenantId,
            'model' => $model,
            'record_id' => $recordId,
            'message_type' => $messageType,
            'subtype' => $subtype,
            'subject' => $subject,
            'body' => $body,
            'body_plain' => strip_tags($body),
            'metadata' => $metadata,
            'author_id' => $authorId,
            'author_type' => 'user',
            'status' => CommunicationMessage::STATUS_QUEUED,
        ]);

        // Create outbox items for each recipient × channel
        foreach ($recipientUserIds as $userId) {
            $user = \App\Models\User::find($userId);
            if (!$user) continue;

            foreach ($channels as $channel) {
                $this->createOutboxItem($message, $channel, [
                    'recipient_user_id' => $userId,
                    'recipient_email' => $user->email,
                ]);
            }
        }

        foreach ($recipientCustomerIds as $customerId) {
            $customer = \App\Models\Customer::find($customerId);
            if (!$customer) continue;

            foreach ($channels as $channel) {
                $this->createOutboxItem($message, $channel, [
                    'recipient_customer_id' => $customerId,
                    'recipient_email' => $customer->email ?? null,
                    'recipient_phone' => $customer->phone ?? null,
                ]);
            }
        }

        foreach ($recipientEmails as $email) {
            foreach ($channels as $channel) {
                if ($channel === 'email') {
                    $this->createOutboxItem($message, 'email', [
                        'recipient_email' => $email,
                    ]);
                }
            }
        }

        ProcessOutboxBatch::dispatch($message->id);

        return $message;
    }

    /**
     * Post a comment/note on a record (chatter-style).
     */
    public function postComment(
        string $model,
        int $recordId,
        string $body,
        int $authorId,
        bool $isInternalNote = false,
        ?string $tenantId = null,
        ?int $parentId = null,
    ): CommunicationMessage {
        $messageType = $isInternalNote
            ? CommunicationMessage::TYPE_INTERNAL_NOTE
            : CommunicationMessage::TYPE_COMMENT;

        $message = CommunicationMessage::create([
            'tenant_id' => $tenantId,
            'model' => $model,
            'record_id' => $recordId,
            'message_type' => $messageType,
            'subtype' => $isInternalNote ? 'internal.note' : 'record.comment',
            'body' => $body,
            'body_plain' => strip_tags($body),
            'author_id' => $authorId,
            'author_type' => 'user',
            'parent_id' => $parentId,
            'status' => CommunicationMessage::STATUS_SENT,
        ]);

        // Notify subscribers (unless internal note — only notify staff)
        if (!$isInternalNote) {
            $subscribers = CommunicationSubscriber::forRecord($model, $recordId)
                ->active()
                ->when($tenantId, fn($q) => $q->forTenant($tenantId))
                ->get();

            $this->routeToSubscribers($message, $subscribers, $message->subtype);
            ProcessOutboxBatch::dispatch($message->id);
        }

        return $message;
    }

    /**
     * Log a system message on a record (no notifications).
     */
    public function logSystem(
        string $model,
        int $recordId,
        string $body,
        string $subtype = 'system.log',
        ?string $tenantId = null,
        array $metadata = [],
    ): CommunicationMessage {
        return CommunicationMessage::create([
            'tenant_id' => $tenantId,
            'model' => $model,
            'record_id' => $recordId,
            'message_type' => CommunicationMessage::TYPE_SYSTEM,
            'subtype' => $subtype,
            'body' => $body,
            'body_plain' => strip_tags($body),
            'metadata' => $metadata,
            'author_type' => 'system',
            'status' => CommunicationMessage::STATUS_SENT,
        ]);
    }

    // ════════════════════════════════════════════════════════
    //  2. SUBSCRIBER MANAGEMENT
    // ════════════════════════════════════════════════════════

    /**
     * Subscribe a user to a record.
     */
    public function subscribe(
        string $model,
        int $recordId,
        ?int $userId = null,
        ?int $customerId = null,
        ?string $email = null,
        ?array $channels = null,
        ?array $subtypes = null,
        ?string $tenantId = null,
    ): CommunicationSubscriber {
        $channels = $channels ?? config('communication.default_channels', ['in_app', 'email']);

        $attributes = [
            'model' => $model,
            'record_id' => $recordId,
            'tenant_id' => $tenantId,
        ];

        if ($userId) {
            $attributes['user_id'] = $userId;
        } elseif ($customerId) {
            $attributes['customer_id'] = $customerId;
        } elseif ($email) {
            $attributes['email'] = $email;
        }

        return CommunicationSubscriber::updateOrCreate(
            $attributes,
            [
                'channels' => $channels,
                'subtypes' => $subtypes,
                'is_active' => true,
            ]
        );
    }

    /**
     * Unsubscribe a user from a record.
     */
    public function unsubscribe(
        string $model,
        int $recordId,
        ?int $userId = null,
        ?int $customerId = null,
        ?string $tenantId = null,
    ): void {
        $query = CommunicationSubscriber::forRecord($model, $recordId);

        if ($tenantId) {
            $query->forTenant($tenantId);
        }

        if ($userId) {
            $query->forUser($userId);
        } elseif ($customerId) {
            $query->forCustomer($customerId);
        }

        $query->update(['is_active' => false]);
    }

    /**
     * Get all subscribers for a record.
     */
    public function getSubscribers(string $model, int $recordId, ?string $tenantId = null): \Illuminate\Support\Collection
    {
        return CommunicationSubscriber::forRecord($model, $recordId)
            ->active()
            ->when($tenantId, fn($q) => $q->forTenant($tenantId))
            ->get();
    }

    // ════════════════════════════════════════════════════════
    //  3. MESSAGE HISTORY (Chatter / Thread)
    // ════════════════════════════════════════════════════════

    /**
     * Get the message thread for a record (chatter).
     */
    public function getThread(
        string $model,
        int $recordId,
        ?string $tenantId = null,
        int $limit = 50,
        ?string $messageType = null,
    ): \Illuminate\Support\Collection {
        return CommunicationMessage::forRecord($model, $recordId)
            ->when($tenantId, fn($q) => $q->forTenant($tenantId))
            ->when($messageType, fn($q) => $q->ofType($messageType))
            ->with('author')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    // ════════════════════════════════════════════════════════
    //  4. OUTBOX PROCESSING
    // ════════════════════════════════════════════════════════

    /**
     * Process pending outbox items for a message.
     */
    public function processOutbox(int $messageId): void
    {
        $message = CommunicationMessage::find($messageId);
        if (!$message) return;

        $outboxItems = CommunicationOutbox::where('message_id', $messageId)
            ->readyToSend()
            ->get();

        foreach ($outboxItems as $outbox) {
            $this->deliverOutboxItem($outbox, $message);
        }

        // Update message status
        if ($message->outboxItems()->where('status', CommunicationOutbox::STATUS_PENDING)->doesntExist()) {
            $hasFailures = $message->outboxItems()->where('status', CommunicationOutbox::STATUS_FAILED)->exists();
            $message->update([
                'status' => $hasFailures ? CommunicationMessage::STATUS_FAILED : CommunicationMessage::STATUS_SENT,
                'sent_at' => now(),
            ]);
        }
    }

    /**
     * Retry failed outbox items.
     */
    public function retryFailed(): int
    {
        $retryable = CommunicationOutbox::retryable()
            ->limit(config('communication.outbox.batch_size', 100))
            ->get();

        $count = 0;
        foreach ($retryable as $outbox) {
            $this->deliverOutboxItem($outbox, $outbox->message);
            $count++;
        }

        return $count;
    }

    // ════════════════════════════════════════════════════════
    //  5. INTERNAL HELPERS
    // ════════════════════════════════════════════════════════

    /**
     * Route a message to subscribers, creating outbox items per channel.
     */
    protected function routeToSubscribers(
        CommunicationMessage $message,
        \Illuminate\Support\Collection $subscribers,
        ?string $subtype,
    ): void {
        foreach ($subscribers as $subscriber) {
            // Skip the author (don't notify yourself)
            if ($subscriber->user_id && $subscriber->user_id === $message->author_id) {
                continue;
            }

            // Check if subscriber should get digest instead of instant
            if ($this->channelRouter->shouldDigest($subscriber)) {
                $this->queueForDigest($message, $subscriber);
                continue;
            }

            // Resolve channels for this subscriber
            $channels = $this->channelRouter->resolveChannels($subscriber, $subtype);

            foreach ($channels as $channel) {
                $this->createOutboxItem($message, $channel, [
                    'recipient_user_id' => $subscriber->user_id,
                    'recipient_customer_id' => $subscriber->customer_id,
                    'recipient_email' => $subscriber->getEmail(),
                    'recipient_phone' => $subscriber->customer?->phone ?? null,
                ]);
            }
        }
    }

    /**
     * Create an outbox item for delivery.
     */
    protected function createOutboxItem(
        CommunicationMessage $message,
        string $channel,
        array $recipientData,
    ): CommunicationOutbox {
        return CommunicationOutbox::create(array_merge([
            'tenant_id' => $message->tenant_id,
            'message_id' => $message->id,
            'channel' => $channel,
            'status' => CommunicationOutbox::STATUS_PENDING,
            'max_retries' => config('communication.outbox.retry_max', 5),
        ], $recipientData));
    }

    /**
     * Deliver a single outbox item through its channel.
     */
    protected function deliverOutboxItem(CommunicationOutbox $outbox, CommunicationMessage $message): void
    {
        $driver = $this->channelRouter->getDriver($outbox->channel);

        if (!$driver) {
            $outbox->markAsFailed("No driver for channel: {$outbox->channel}", false);
            return;
        }

        // Mark as processing
        $outbox->update(['status' => CommunicationOutbox::STATUS_PROCESSING]);

        $result = $driver->send($outbox, $message);

        if ($result['success']) {
            $outbox->markAsSent($result['external_id'] ?? null);

            // Log delivery event
            $this->logChannelEvent($outbox, $message, 'sent');
        } else {
            $outbox->markAsFailed($result['error'] ?? 'Unknown error');

            // Log failure event
            $this->logChannelEvent($outbox, $message, 'failed', [
                'error' => $result['error'] ?? 'Unknown error',
            ]);
        }
    }

    /**
     * Queue a message for digest delivery.
     */
    protected function queueForDigest(CommunicationMessage $message, CommunicationSubscriber $subscriber): void
    {
        $preferences = CommunicationPreference::query();

        if ($subscriber->user_id) {
            $preferences->forUser($subscriber->user_id);
        } elseif ($subscriber->customer_id) {
            $preferences->forCustomer($subscriber->customer_id);
        }

        $pref = $preferences->first();
        $digestType = $pref?->delivery_mode === CommunicationPreference::MODE_DIGEST_WEEKLY ? 'weekly' : 'daily';

        DB::table('communication_digest_queue')->insert([
            'tenant_id' => $message->tenant_id,
            'user_id' => $subscriber->user_id,
            'customer_id' => $subscriber->customer_id,
            'message_id' => $message->id,
            'digest_type' => $digestType,
            'is_sent' => false,
            'scheduled_for' => $digestType === 'daily'
                ? now()->addDay()->startOfDay()->setTimeFromTimeString($pref?->digest_time ?? '08:00')
                : now()->next('Monday')->startOfDay()->setTimeFromTimeString($pref?->digest_time ?? '08:00'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Log a channel delivery event for auditing.
     */
    protected function logChannelEvent(
        CommunicationOutbox $outbox,
        CommunicationMessage $message,
        string $event,
        array $eventData = [],
    ): void {
        CommunicationChannelLog::create([
            'tenant_id' => $outbox->tenant_id,
            'outbox_id' => $outbox->id,
            'message_id' => $message->id,
            'channel' => $outbox->channel,
            'provider' => $outbox->provider,
            'event' => $event,
            'event_data' => $eventData ?: null,
            'occurred_at' => now(),
        ]);
    }
}
