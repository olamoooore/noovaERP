<?php

namespace Modules\Communication\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Communication\Models\CommunicationMessage;
use Modules\Communication\Models\CommunicationOutbox;
use Modules\Communication\Models\CommunicationChannelLog;

/**
 * CleanupOldMessages — Removes old communication data based on retention policy.
 *
 * Schedule:
 *   $schedule->job(new CleanupOldMessages())->daily();
 */
class CleanupOldMessages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct()
    {
        $this->queue = 'communication-maintenance';
    }

    public function handle(): void
    {
        $retentionDays = config('communication.retention.message_days', 365);
        $logRetentionDays = config('communication.retention.log_days', 90);
        $cutoffMessages = now()->subDays($retentionDays);
        $cutoffLogs = now()->subDays($logRetentionDays);

        // Delete old channel logs
        $logsDeleted = CommunicationChannelLog::where('occurred_at', '<', $cutoffLogs)->delete();

        // Delete old sent digest queue items
        $digestsDeleted = DB::table('communication_digest_queue')
            ->where('is_sent', true)
            ->where('sent_at', '<', $cutoffLogs)
            ->delete();

        // Delete old outbox items (completed)
        $outboxDeleted = CommunicationOutbox::whereIn('status', [
            CommunicationOutbox::STATUS_SENT,
            CommunicationOutbox::STATUS_DELIVERED,
            CommunicationOutbox::STATUS_FAILED,
        ])
            ->where('created_at', '<', $cutoffMessages)
            ->delete();

        // Delete old system messages (keep comments/notes longer)
        $messagesDeleted = CommunicationMessage::whereIn('message_type', [
            CommunicationMessage::TYPE_SYSTEM,
            CommunicationMessage::TYPE_NOTIFICATION,
        ])
            ->where('created_at', '<', $cutoffMessages)
            ->doesntHave('outboxItems') // Only delete if no pending outbox items
            ->delete();

        Log::info('Communication: Cleanup completed', [
            'messages_deleted' => $messagesDeleted,
            'outbox_deleted' => $outboxDeleted,
            'logs_deleted' => $logsDeleted,
            'digests_deleted' => $digestsDeleted,
        ]);
    }
}
