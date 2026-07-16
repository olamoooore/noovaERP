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
use Modules\Communication\Services\CommunicationEngine;

/**
 * SendDigest — Batches and sends digest emails/notifications.
 *
 * Collects all queued digest items for a given type (daily/weekly)
 * and sends them as a single consolidated message per user.
 *
 * Schedule:
 *   $schedule->job(new SendDigest('daily'))->dailyAt('08:00');
 *   $schedule->job(new SendDigest('weekly'))->weeklyOn(1, '08:00');
 */
class SendDigest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public string $digestType = 'daily',
    ) {
        $this->queue = 'communication';
    }

    public function handle(CommunicationEngine $engine): void
    {
        // Get all unsent digest items for this type
        $digestItems = DB::table('communication_digest_queue')
            ->where('digest_type', $this->digestType)
            ->where('is_sent', false)
            ->where('scheduled_for', '<=', now())
            ->get()
            ->groupBy(fn ($item) => ($item->user_id ?? 'c'.$item->customer_id));

        $sentCount = 0;

        foreach ($digestItems as $recipientKey => $items) {
            $messageIds = $items->pluck('message_id')->toArray();
            $messages = CommunicationMessage::whereIn('id', $messageIds)->get();

            if ($messages->isEmpty()) {
                continue;
            }

            // Build digest body
            $digestBody = $this->buildDigestHtml($messages);
            $firstItem = $items->first();

            $recipientUserIds = $firstItem->user_id ? [$firstItem->user_id] : [];
            $recipientCustomerIds = $firstItem->customer_id ? [$firstItem->customer_id] : [];

            // Send the consolidated digest
            $engine->sendDirect(
                subject: ucfirst($this->digestType).' Activity Digest — '.now()->format('M j, Y'),
                body: $digestBody,
                recipientUserIds: $recipientUserIds,
                recipientCustomerIds: $recipientCustomerIds,
                channels: ['email', 'in_app'],
                messageType: CommunicationMessage::TYPE_NOTIFICATION,
                subtype: 'digest.'.$this->digestType,
                tenantId: $firstItem->tenant_id,
            );

            // Mark as sent
            DB::table('communication_digest_queue')
                ->whereIn('id', $items->pluck('id'))
                ->update(['is_sent' => true, 'sent_at' => now(), 'updated_at' => now()]);

            $sentCount++;
        }

        if ($sentCount > 0) {
            Log::info("Communication: Sent {$sentCount} {$this->digestType} digests");
        }
    }

    /**
     * Build a consolidated digest HTML from multiple messages.
     */
    protected function buildDigestHtml(\Illuminate\Support\Collection $messages): string
    {
        $html = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">';
        $html .= '<h2 style="color: #333; border-bottom: 2px solid #007bff; padding-bottom: 8px;">';
        $html .= ucfirst($this->digestType).' Activity Summary';
        $html .= '</h2>';

        foreach ($messages as $message) {
            $html .= '<div style="margin: 16px 0; padding: 12px; background: #f8f9fa; border-radius: 6px; border-left: 3px solid #007bff;">';
            $html .= '<strong style="color: #333;">'.e($message->subject ?: $message->subtype).'</strong>';
            $html .= '<p style="color: #666; margin: 4px 0 0 0; font-size: 14px;">';
            $html .= \Illuminate\Support\Str::limit(strip_tags($message->body), 200);
            $html .= '</p>';
            $html .= '<small style="color: #999;">'.$message->created_at->diffForHumans().'</small>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }
}
