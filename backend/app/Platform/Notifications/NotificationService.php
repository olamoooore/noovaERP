<?php

namespace App\Platform\Notifications;

use App\Models\User;
use App\Platform\Notifications\Contracts\NotificationServiceContract;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotificationService implements NotificationServiceContract
{
    /**
     * Dispatch a notification to a user via mail.
     * Checks user preferences before sending.
     */
    public function dispatch(string $event, User $user, array $data = []): void
    {
        // Check if user has opted out
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

        // Create log entry
        $log = NotificationLog::create([
            'id' => Str::uuid(),
            'tenant_id' => tenant()?->id,
            'user_id' => $user->id,
            'event' => $event,
            'channel' => 'mail',
            'subject' => $data['subject'] ?? $this->defaultSubject($event),
            'body' => $data['body'] ?? $this->defaultBody($event, $data),
            'data' => $data,
            'status' => 'pending',
            'sent_at' => null,
        ]);

        try {
            // Send mail
            $toEmail = $user->email;
            if (! $toEmail) {
                $log->update(['status' => 'failed', 'error' => 'No email address']);

                return;
            }

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
            default => "Event: {$event}\n\n".json_encode($data, JSON_PRETTY_PRINT),
        };
    }
}
