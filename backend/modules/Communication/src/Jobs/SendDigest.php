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
            // P-06: tenant is part of the group key — a recipient's digest
            // must be composed and sent inside its owning tenant context
            // (mail config + lookups), never from the worker's central one.
            ->groupBy(fn ($item) => ($item->tenant_id ?? '-').'|'.($item->user_id ?? 'c'.$item->customer_id));

        $sentCount = 0;

        foreach ($digestItems as $recipientKey => $items) {
            $messageIds = $items->pluck('message_id')->toArray();
            $messages = CommunicationMessage::whereIn('id', $messageIds)->get();

            if ($messages->isEmpty()) {
                continue;
            }

            $firstItem = $items->first();
            $tenantKey = (string) ($firstItem->tenant_id ?? '');

            // Messages are central rows (already loaded); only DELIVERY needs
            // the tenant context (mail config, subscriber lookups).
            $send = function () use ($engine, $firstItem, $items, $messages, &$sentCount) {
                // Build digest body
                $digestBody = $this->buildDigestHtml($messages);

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
            };

            if ($tenantKey !== '' && $tenantKey !== '-') {
                $tenant = null;
                try {
                    $tenant = DB::transaction(fn () => \App\Models\Tenant::query()->find($tenantKey));
                } catch (\Throwable) {
                    // malformed tenant id — fall through to the skip guard
                }
                if ($tenant === null) {
                    \Illuminate\Support\Facades\Log::warning('SendDigest: unknown tenant, skipping (P-06 guard)', ['tenant_id' => $tenantKey]);

                    continue;
                }

                $tenancy = app(\Stancl\Tenancy\Tenancy::class);
                $tenancy->initialize($tenant);
                try {
                    $send();
                } finally {
                    $tenancy->end();
                }
            } else {
                $send();
            }
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
