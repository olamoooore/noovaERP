<?php

namespace Modules\Communication\Services\Channels;

use Illuminate\Support\Facades\Log;
use Modules\Communication\Models\CommunicationMessage;
use Modules\Communication\Models\CommunicationOutbox;

/**
 * WhatsAppChannel — Sends messages via WhatsApp Business API.
 *
 * Supports Meta Cloud API and Twilio WhatsApp.
 */
class WhatsAppChannel implements ChannelInterface
{
    public function getChannel(): string
    {
        return 'whatsapp';
    }

    public function isEnabled(): bool
    {
        return config('communication.channels.whatsapp.enabled', false);
    }

    public function send(CommunicationOutbox $outbox, CommunicationMessage $message): array
    {
        if (! $this->isEnabled()) {
            return ['success' => false, 'error' => 'WhatsApp channel is not enabled'];
        }

        try {
            $phone = $outbox->recipient_phone;
            if (! $phone) {
                return ['success' => false, 'error' => 'No recipient phone number'];
            }

            $body = $message->body_plain ?? strip_tags($message->body ?? '');
            $driver = config('communication.channels.whatsapp.driver');

            $result = match ($driver) {
                'meta' => $this->sendViaMeta($phone, $body, $message),
                'twilio' => $this->sendViaTwilio($phone, $body, $message),
                default => ['success' => false, 'error' => "Unknown WhatsApp driver: {$driver}"],
            };

            return $result;

        } catch (\Throwable $e) {
            Log::error('Communication: WhatsApp failed', [
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

    /** NOTE: unimplemented drivers must FAIL CLOSED (platform defect P-05: stubs previously returned success:true, silently faking delivery and emptying the retry backlog). */
    protected function sendViaMeta(string $phone, string $body, CommunicationMessage $message): array
    {
        // TODO: Implement Meta Cloud API integration
        // POST https://graph.facebook.com/v17.0/{phone_number_id}/messages
        Log::error('Communication: Meta driver not implemented — failing closed (P-05)');

        return ['success' => false, 'error' => 'driver_not_implemented'];
    }

    protected function sendViaTwilio(string $phone, string $body, CommunicationMessage $message): array
    {
        // TODO: Implement Twilio WhatsApp integration
        Log::error('Communication: Twilio driver not implemented — failing closed (P-05)');

        return ['success' => false, 'error' => 'driver_not_implemented'];
    }
}
