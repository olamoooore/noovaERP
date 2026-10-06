<?php

namespace Modules\Communication\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Communication\Jobs\ProcessOutboxBatch;
use Modules\Communication\Models\CommunicationAttachment;
use Modules\Communication\Models\CommunicationChannelLog;
use Modules\Communication\Models\CommunicationMessage;
use Modules\Communication\Models\CommunicationOutbox;
use Modules\Communication\Models\CommunicationPreference;
use Modules\Communication\Models\CommunicationReadState;
use Modules\Communication\Models\CommunicationSubscriber;
use Modules\Communication\Support\EventSubtypeRegistry;

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
    /**
     * Roles that can see all internal notes regardless of authorship.
     * Issue #77 (CRITICAL): keep in sync with role-permission seeder and
     * the DB-level row-level security policy in
     * 2026_09_18_000002_communication_messages_internal_note_rls.
     */
    public const STAFF_ROLES = ['owner', 'admin', 'manager', 'staff'];

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
        // Issue #82: reject unknown subtypes instead of silently skipping delivery.
        EventSubtypeRegistry::assertValid($subtype);
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
            ->when($tenantId, fn ($q) => $q->forTenant($tenantId))
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
        if ($subtype !== null) {
            EventSubtypeRegistry::assertValid($subtype);
        }
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
            if (! $user) {
                continue;
            }

            foreach ($channels as $channel) {
                $this->createOutboxItem($message, $channel, [
                    'recipient_user_id' => $userId,
                    'recipient_email' => $user->email,
                ]);
            }
        }

        foreach ($recipientCustomerIds as $customerId) {
            $customer = \App\Models\Customer::find($customerId);
            if (! $customer) {
                continue;
            }

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
        array $metadata = [],
    ): CommunicationMessage {
        $messageType = $isInternalNote
            ? CommunicationMessage::TYPE_INTERNAL_NOTE
            : CommunicationMessage::TYPE_COMMENT;

        // Issue #636: the parent_id column was dropped as dead (#80) and was
        // never fillable, so a reply's parent is kept in metadata.parent_id —
        // the key the chatter uses to thread replies under their message.
        if ($parentId !== null) {
            $metadata['parent_id'] = $parentId;
        }

        $message = CommunicationMessage::create([
            'tenant_id' => $tenantId,
            'model' => $model,
            'record_id' => $recordId,
            'message_type' => $messageType,
            'subtype' => $isInternalNote ? 'internal.note' : 'record.comment',
            'body' => $body,
            'body_plain' => strip_tags($body),
            'metadata' => $metadata === [] ? null : $metadata,
            'author_id' => $authorId,
            'author_type' => 'user',
            'status' => CommunicationMessage::STATUS_SENT,
        ]);

        // Notify subscribers (unless internal note — only notify staff)
        if (! $isInternalNote) {
            $subscribers = CommunicationSubscriber::forRecord($model, $recordId)
                ->active()
                ->when($tenantId, fn ($q) => $q->forTenant($tenantId))
                ->get();

            $this->routeToSubscribers($message, $subscribers, $message->subtype);
            ProcessOutboxBatch::dispatch($message->id);
        }

        return $message;
    }

    /**
     * Issue #636: "Message customer" from a record's chatter.
     *
     * Logs ONE outbound message on the record (email or WhatsApp) and queues
     * it to the record's customer contact only — never to the record's
     * subscribers, so it is a deliberate customer-facing send, distinct from
     * internal notes. `metadata` carries direction/channel/recipient and the
     * RFC Message-ID the email is stamped with, so an inbound reply's
     * In-Reply-To can be threaded back under this message.
     */
    public function sendToRecordContact(
        string $model,
        int $recordId,
        string $channel,
        string $subject,
        string $bodyHtml,
        string $bodyPlain,
        ?string $email,
        ?string $phone,
        int $authorId,
        ?string $tenantId = null,
        array $metadata = [],
    ): CommunicationMessage {
        $messageType = match ($channel) {
            'email' => CommunicationMessage::TYPE_EMAIL,
            'whatsapp' => CommunicationMessage::TYPE_WHATSAPP,
            default => throw new \InvalidArgumentException("Unsupported customer message channel '{$channel}'."),
        };

        $message = CommunicationMessage::create([
            'tenant_id' => $tenantId,
            'model' => $model,
            'record_id' => $recordId,
            'message_type' => $messageType,
            'subtype' => EventSubtypeRegistry::CUSTOMER_MESSAGE,
            'subject' => $subject,
            'body' => $bodyHtml,
            'body_plain' => $bodyPlain,
            'metadata' => $metadata,
            'author_id' => $authorId,
            'author_type' => 'user',
            'status' => CommunicationMessage::STATUS_QUEUED,
        ]);

        $this->createOutboxItem($message, $channel, $channel === 'email'
            ? ['recipient_email' => $email]
            : ['recipient_phone' => $phone]);

        ProcessOutboxBatch::dispatch($message->id);

        return $message->fresh() ?? $message;
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
        // Issue #82: unknown subtypes fail loudly.
        EventSubtypeRegistry::assertValid($subtype);

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
            ->when($tenantId, fn ($q) => $q->forTenant($tenantId))
            ->get();
    }

    // ════════════════════════════════════════════════════════
    //  3. MESSAGE HISTORY (Chatter / Thread)
    // ════════════════════════════════════════════════════════

    /**
     * Get the message thread for a record (chatter).
     *
     * Issue #77 (CRITICAL): internal notes must NEVER be returned to non-staff
     * users. The previous implementation returned all messages regardless of
     * caller role. Defence-in-depth: this application-level filter is paired
     * with a PostgreSQL row-level security policy installed by migration
     * 2026_09_18_000002_communication_messages_internal_note_rls.
     *
     * Internal notes are visible only to:
     *   - the author of the note, OR
     *   - users with one of $staffRoles (Owner/Admin/Staff/Manager).
     */

    // ─── Issue #88: attachments ────────────────────────────────────────

    public function attachToMessage(
        int $messageId,
        \Illuminate\Http\UploadedFile $file,
        ?int $uploadedById = null,
        ?string $tenantId = null,
        bool $isInline = false,
        string $disk = 'local',
    ): CommunicationAttachment {
        $path = $file->store("communication/attachments/{$messageId}", $disk);

        return CommunicationAttachment::create([
            'tenant_id' => $tenantId,
            'message_id' => $messageId,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize(),
            'storage_disk' => $disk,
            'storage_path' => $path,
            'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
            'is_inline' => $isInline,
            'uploaded_by_id' => $uploadedById,
        ]);
    }

    public function deleteAttachment(CommunicationAttachment $attachment): void
    {
        \Illuminate\Support\Facades\Storage::disk($attachment->storage_disk)->delete($attachment->storage_path);
        $attachment->delete();
    }

    // ─── Issue #86: per-user read state ────────────────────────────────

    public function markAsRead(int $messageId, int $userId, ?string $tenantId = null): CommunicationReadState
    {
        return CommunicationReadState::updateOrCreate(
            ['message_id' => $messageId, 'user_id' => $userId],
            ['tenant_id' => $tenantId, 'read_at' => now()],
        );
    }

    /**
     * Mark every message on a record as read for the user.
     *
     * @return int number of messages newly marked read
     */
    public function markAllAsRead(string $model, int $recordId, int $userId, ?string $tenantId = null): int
    {
        $messageIds = CommunicationMessage::forRecord($model, $recordId)
            ->when($tenantId, fn ($q) => $q->forTenant($tenantId))
            ->pluck('id');

        if ($messageIds->isEmpty()) {
            return 0;
        }

        $alreadyRead = CommunicationReadState::where('user_id', $userId)
            ->whereIn('message_id', $messageIds)
            ->pluck('message_id');

        $toMark = $messageIds->diff($alreadyRead);

        foreach ($toMark as $messageId) {
            CommunicationReadState::create([
                'tenant_id' => $tenantId,
                'message_id' => $messageId,
                'user_id' => $userId,
                'read_at' => now(),
            ]);
        }

        return $toMark->count();
    }

    /**
     * Unread messages for a user, optionally scoped to one record.
     */
    public function unreadCount(int $userId, ?string $tenantId = null, ?string $model = null, ?int $recordId = null): int
    {
        $query = CommunicationMessage::query()
            ->when($tenantId, fn ($q) => $q->forTenant($tenantId))
            ->when($model, fn ($q) => $q->where('model', $model))
            ->when($recordId, fn ($q) => $q->where('record_id', $recordId))
            ->whereNotIn('id', CommunicationReadState::where('user_id', $userId)->select('message_id'));

        return $query->count();
    }

    public function getThread(
        string $model,
        int $recordId,
        ?string $tenantId = null,
        int $limit = 50,
        ?string $messageType = null,
        ?int $currentUserId = null,
        ?string $userRole = null,
    ): \Illuminate\Support\Collection {
        $query = CommunicationMessage::forRecord($model, $recordId)
            ->when($tenantId, fn ($q) => $q->forTenant($tenantId))
            ->when($messageType, fn ($q) => $q->ofType($messageType))
            ->with(['author', 'attachments']);

        // Internal-note visibility gate (Issue #77). If we don't know the user,
        // fail closed: do not return internal notes.
        $isStaff = $userRole !== null && in_array($userRole, self::STAFF_ROLES, true);
        if (! $isStaff) {
            $query->where(function ($q) use ($currentUserId) {
                $q->where('message_type', '!=', CommunicationMessage::TYPE_INTERNAL_NOTE);
                if ($currentUserId !== null) {
                    // Authors can see their own internal notes regardless of role.
                    $q->orWhere('author_id', $currentUserId);
                }
            });
        }

        $messages = $query
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        // Issue #86: per-user read flags for the returned messages.
        if ($currentUserId !== null && $messages->isNotEmpty()) {
            $readIds = CommunicationReadState::where('user_id', $currentUserId)
                ->whereIn('message_id', $messages->pluck('id'))
                ->pluck('message_id')
                ->all();

            $messages->each(fn (CommunicationMessage $message) => $message->setAttribute(
                'is_read',
                in_array($message->id, $readIds, true)
            ));
        }

        return $messages;
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
        if (! $message) {
            return;
        }

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

        // Platform defect P-06 (closed for the retry path): deliveries must
        // run inside the OWNING tenant's context — the email channel reads
        // process-wide mail config, which the tenant bootstrap refreshes
        // from that tenant's settings on initialization. Previously every
        // scheduled retry ran central-only, so retried mail could go out on
        // the wrong (or default) transport.
        $byTenant = $retryable->groupBy(function ($outbox) {
            return (string) ($outbox->tenant_id ?? $outbox->message?->tenant_id ?? '');
        });

        foreach ($byTenant as $tenantKey => $items) {
            $tenantKey = (string) $tenantKey;

            if ($tenantKey === '') {
                // Central-tenant messages (rare, pre-backfill): deliver in
                // the current context rather than dropping them.
                foreach ($items as $outbox) {
                    try {
                        $this->deliverOutboxItem($outbox, $outbox->message);
                        $count++;
                    } catch (\Throwable $e) {
                        Log::error('Communication: retry delivery failed', ['outbox_id' => $outbox->id, 'error' => $e->getMessage()]);
                    }
                }

                continue;
            }

            $tenant = null;

            // SAVEPOINT via nested transaction: a malformed (non-uuid)
            // tenant id would otherwise abort Postgres' whole transaction
            // (25P02) even though PHP catches the throw.
            try {
                $tenant = DB::transaction(fn () => \App\Models\Tenant::query()->find($tenantKey));
            } catch (\Throwable) {
                $tenant = null;
            }

            if ($tenant === null) {
                // Never deliver under a WRONG/absent tenant context.
                foreach ($items as $outbox) {
                    $outbox->markAsFailed("Tenant {$tenantKey} not found for retry (P-06 guard)", true);
                }

                Log::warning('Communication: retry skipped — unknown tenant', [
                    'tenant_id' => $tenantKey,
                    'items' => $items->count(),
                ]);

                continue;
            }

            $tenancy = app(\Stancl\Tenancy\Tenancy::class);
            $tenancy->initialize($tenant);

            try {
                foreach ($items as $outbox) {
                    try {
                        $this->deliverOutboxItem($outbox, $outbox->message);
                        $count++;
                    } catch (\Throwable $e) {
                        Log::error('Communication: retry delivery failed', ['outbox_id' => $outbox->id, 'error' => $e->getMessage()]);
                    }
                }
            } finally {
                $tenancy->end();
            }
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

        if (! $driver) {
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
