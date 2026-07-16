<?php

namespace Modules\Communication\Services\Channels;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Communication\Models\CommunicationMessage;
use Modules\Communication\Models\CommunicationOutbox;

/**
 * InAppChannel — Delivers notifications to the in-app notification center.
 *
 * Uses Laravel's database notification system for real-time in-app delivery.
 */
class InAppChannel implements ChannelInterface
{
    public function getChannel(): string
    {
        return 'in_app';
    }

    public function isEnabled(): bool
    {
        return config('communication.channels.in_app.enabled', true);
    }

    public function send(CommunicationOutbox $outbox, CommunicationMessage $message): array
    {
        try {
            // Determine notifiable type and ID
            $notifiableType = null;
            $notifiableId = null;

            if ($outbox->recipient_user_id) {
                $notifiableType = 'App\\Models\\User';
                $notifiableId = $outbox->recipient_user_id;
            } elseif ($outbox->recipient_customer_id) {
                $notifiableType = 'App\\Models\\Customer';
                $notifiableId = $outbox->recipient_customer_id;
            }

            if (! $notifiableType || ! $notifiableId) {
                return ['success' => false, 'error' => 'No notifiable user/customer'];
            }

            // Store as Laravel database notification
            $notificationId = \Illuminate\Support\Str::uuid()->toString();

            DB::table('notifications')->insert([
                'id' => $notificationId,
                'type' => 'Modules\\Communication\\Notifications\\CommunicationNotification',
                'notifiable_type' => $notifiableType,
                'notifiable_id' => $notifiableId,
                'data' => json_encode([
                    'message_id' => $message->id,
                    'subject' => $message->subject,
                    'body' => \Illuminate\Support\Str::limit(strip_tags($message->body), 200),
                    'model' => $message->model,
                    'record_id' => $message->record_id,
                    'subtype' => $message->subtype,
                    'author_id' => $message->author_id,
                    'author_type' => $message->author_type,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['success' => true, 'external_id' => $notificationId];

        } catch (\Throwable $e) {
            Log::error('Communication: In-app notification failed', [
                'outbox_id' => $outbox->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function sendBatch(\Illuminate\Support\Collection $outboxItems): array
    {
        $results = [];

        foreach ($outboxItems as $outbox) {
            $results[$outbox->id] = $this->send($outbox, $outbox->message);
        }

        return $results;
    }
}
