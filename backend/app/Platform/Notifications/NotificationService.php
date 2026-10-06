<?php

namespace App\Platform\Notifications;

use App\Models\User;
use App\Platform\Notifications\Contracts\NotificationServiceContract;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotificationService implements NotificationServiceContract
{
    /** The channels this service can deliver. */
    public const CHANNELS = ['in_app', 'mail'];

    /**
     * Issue #907: the channel stored on a document-scoped mute row. A row
     * keyed (user, event, document_type, document_id) with this channel and
     * mail_enabled = false mutes every channel for that document.
     */
    public const DOCUMENT_MUTE_CHANNEL = 'all';

    /**
     * Dispatch a notification to a user via mail.
     * Checks user preferences before sending.
     */
    public function dispatch(string $event, User $user, array $data = []): void
    {
        // Check if user has opted out. The mail row is the legacy
        // all-or-nothing opt-out (approvals, chatter, …): a user who
        // disabled mail for the event receives nothing at all.
        $preference = NotificationPreference::where('user_id', $user->id)
            ->where('event', $event)
            ->where('channel', 'mail')
            ->first();

        if ($preference && ! $preference->mail_enabled) {
            Log::info('Notification skipped — user opted out', [
                'user_id' => $user->id,
                'event' => $event,
            ]);

            return;
        }

        $this->dispatchOnChannels($event, $user, $data, self::CHANNELS);
    }

    /**
     * Dispatch a notification on an explicit set of channels.
     *
     * Issue #903: the per-user/per-channel preference decision is made by the
     * caller (SalesNotificationDispatcher), which knows the event's default
     * audiences and channels; this method only delivers. The notification_logs
     * row remains the in-app notification and the mail delivery record.
     *
     * @param  string[]  $channels  subset of self::CHANNELS
     */
    public function dispatchOnChannels(string $event, User $user, array $data, array $channels): void
    {
        // Issue #907: a document-scoped mute suppresses every channel of that
        // one document for this user, before any log row is written.
        if ($this->isDocumentMuted(
            (int) $user->id,
            $event,
            isset($data['document_type']) ? (string) $data['document_type'] : null,
            $data['document_id'] ?? null,
        )) {
            Log::info('Notification skipped — document muted for this user', [
                'user_id' => $user->id,
                'event' => $event,
                'document_type' => $data['document_type'] ?? null,
                'document_id' => $data['document_id'] ?? null,
            ]);

            return;
        }

        $channels = array_values(array_intersect($channels, self::CHANNELS));

        if ($channels === []) {
            Log::info('Notification skipped — every requested channel is disabled', [
                'user_id' => $user->id,
                'event' => $event,
            ]);

            return;
        }

        $sendMail = in_array('mail', $channels, true);

        // Create log entry. The log row IS the in-app notification; when the
        // in-app channel is disabled but mail is on, the row still carries the
        // mail delivery state (the inbox endpoints filter in-app-muted events).
        $log = NotificationLog::create([
            'id' => Str::uuid(),
            'tenant_id' => tenant()?->id,
            'user_id' => $user->id,
            'event' => $event,
            'channel' => $sendMail ? 'mail' : 'in_app',
            'subject' => $data['subject'] ?? $this->defaultSubject($event),
            'body' => $data['body'] ?? $this->defaultBody($event, $data),
            'data' => $data,
            'status' => 'pending',
            'sent_at' => null,
        ]);

        if (! $sendMail) {
            $log->update([
                'status' => 'skipped',
                'error' => 'Mail channel is disabled for this notification.',
            ]);

            return;
        }

        // The log row IS the in-app notification; `status` tracks the mail leg
        // only. A recipient we cannot deliver to is not a notification
        // failure: attempting the send produces a provider error
        // ("Invalid `to` field") and brands a perfectly readable in-app
        // notification as "failed".
        $toEmail = $user->email;
        if (! $toEmail || ! filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $log->update([
                'status' => 'skipped',
                'error' => $toEmail
                    ? "Mail not sent: \"{$toEmail}\" is not a deliverable email address."
                    : 'Mail not sent: the user account has no email address.',
            ]);

            Log::warning('Notification mail skipped — undeliverable recipient', [
                'user_id' => $user->id,
                'event' => $event,
                'email' => $toEmail,
            ]);

            return;
        }

        try {
            $subject = $log->subject;
            $body = $log->body;

            \Illuminate\Support\Facades\Mail::raw($body, function ($message) use ($toEmail, $subject) {
                $message->to($toEmail)
                    ->subject($subject);
            });

            $log->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            Log::info('Notification dispatched', [
                'user_id' => $user->id,
                'event' => $event,
                'channel' => 'mail',
            ]);
        } catch (\Exception $e) {
            $log->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            Log::error('Notification dispatch failed', [
                'user_id' => $user->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Set a user's notification preference for an event.
     */
    public function setPreference(int $userId, string $event, string $channel, bool $enabled): void
    {
        NotificationPreference::updateOrCreate(
            ['user_id' => $userId, 'event' => $event, 'channel' => $channel],
            ['mail_enabled' => $enabled, 'tenant_id' => tenant()?->id]
        );
    }

    /**
     * Issue #907 — mute or un-mute every channel for one document, for one
     * user. A mute is a single additive row beyond the #903 event-wide
     * preference; un-muting deletes it so delivery returns to the event
     * preference.
     */
    public function setDocumentMute(
        int $userId,
        string $event,
        string $documentType,
        string $documentId,
        bool $muted,
    ): void {
        if (! $muted) {
            NotificationPreference::query()
                ->where('user_id', $userId)
                ->where('event', $event)
                ->where('document_type', $documentType)
                ->where('document_id', $documentId)
                ->delete();

            return;
        }

        NotificationPreference::updateOrCreate(
            [
                'user_id' => $userId,
                'event' => $event,
                'channel' => self::DOCUMENT_MUTE_CHANNEL,
                'document_type' => $documentType,
                'document_id' => $documentId,
            ],
            ['mail_enabled' => false, 'tenant_id' => tenant()?->id]
        );
    }

    /**
     * Is every channel of this document muted for this user?
     */
    public function isDocumentMuted(int $userId, string $event, ?string $documentType, mixed $documentId): bool
    {
        if ($documentType === null || $documentType === '' || $documentId === null || $documentId === '') {
            return false;
        }

        return NotificationPreference::query()
            ->where('user_id', $userId)
            ->where('event', $event)
            ->where('document_type', $documentType)
            ->where('document_id', (string) $documentId)
            ->where('mail_enabled', false)
            ->exists();
    }

    /**
     * The document mutes that apply to a user, as "event|document_type|document_id"
     * keys, so inbox listings can flag muted documents without an N+1 query.
     *
     * @return array<int, string>
     */
    public function mutedDocumentKeys(int $userId): array
    {
        return NotificationPreference::query()
            ->where('user_id', $userId)
            ->whereNotNull('document_type')
            ->whereNotNull('document_id')
            ->where('mail_enabled', false)
            ->get()
            ->map(fn (NotificationPreference $preference) => $preference->event
                .'|'.$preference->document_type
                .'|'.$preference->document_id)
            ->all();
    }

    /**
     * Get a user's notification preferences.
     */
    public function getPreferences(int $userId): array
    {
        return NotificationPreference::where('user_id', $userId)
            ->get()
            ->toArray();
    }

    /**
     * Get notification logs for a user.
     */
    public function getLogs(int $userId, ?string $event = null, int $limit = 50): array
    {
        $query = NotificationLog::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($event) {
            $query->where('event', $event);
        }

        return $query->get()->toArray();
    }

    /**
     * Mark a notification as read.
     */
    public function markRead(string $logId): bool
    {
        return NotificationLog::where('id', $logId)
            ->update(['read_at' => now()]) > 0;
    }

    private function defaultSubject(string $event): string
    {
        return match ($event) {
            'approval.requested' => 'Approval Required',
            'approval.approved' => 'Approval Granted',
            'approval.rejected' => 'Approval Rejected',
            'approval.escalated' => 'Approval Escalated',
            'exception.logged' => 'System Exception',
            'exception.assigned' => 'Exception Assigned',
            default => ucfirst(str_replace('.', ' ', $event)),
        };
    }

    private function defaultBody(string $event, array $data): string
    {
        $type = $data['type'] ?? 'unknown';
        $amount = $data['amount'] ?? 'N/A';
        $reason = $data['reason'] ?? 'N/A';
        $approvedBy = $data['approved_by'] ?? 'N/A';

        return match ($event) {
            'approval.requested' => "An approval request requires your attention.\n\nType: {$type}\nAmount: {$amount}\nReason: {$reason}",
            'approval.approved' => "Your approval request has been approved.\n\nType: {$type}\nApproved by: {$approvedBy}",
            'approval.rejected' => "Your approval request has been rejected.\n\nType: {$type}\nReason: {$reason}",
            'approval.escalated' => "An approval request has been escalated to you.\n\nType: {$type}\nAmount: {$amount}",
            // Never dump the raw payload at the user: render it as readable
            // "Label: value" lines (the bell previously showed raw JSON for
            // any event without an explicit body).
            default => $this->humanise($event, $data),
        };
    }

    /**
     * Readable fallback body for events that do not supply their own.
     */
    private function humanise(string $event, array $data): string
    {
        $lines = ['Event: '.ucfirst(str_replace(['.', '_'], ' ', $event))];

        foreach ($data as $key => $value) {
            if (in_array($key, ['subject', 'body'], true)) {
                continue;
            }
            if ($value === null || $value === '' || is_array($value) || is_object($value)) {
                continue;
            }

            $lines[] = ucwords(str_replace('_', ' ', (string) $key)).': '.$value;
        }

        return implode("\n", $lines);
    }
}
