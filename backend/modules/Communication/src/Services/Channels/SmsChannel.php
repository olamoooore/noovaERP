<?php

namespace Modules\Communication\Services\Channels;

use Illuminate\Support\Facades\Log;
use Modules\Communication\Models\CommunicationMessage;
use Modules\Communication\Models\CommunicationOutbox;

/**
 * SmsChannel — Sends messages via SMS.
 *
 * Supports multiple providers: Twilio, Vonage, Africa's Talking.
 * Provider is configurable per tenant.
 */
class SmsChannel implements ChannelInterface
{
    public function getChannel(): string
    {
        return 'sms';
    }

    public function isEnabled(): bool
    {
        return config('communication.channels.sms.enabled', false);
    }

    public function send(CommunicationOutbox $outbox, CommunicationMessage $message): array
    {
        if (! $this->isEnabled()) {
            return ['success' => false, 'error' => 'SMS channel is not enabled'];
        }

        try {
            $phone = $outbox->recipient_phone;
            if (! $phone) {
                return ['success' => false, 'error' => 'No recipient phone number'];
            }

            $body = $message->body_plain ?? strip_tags($message->body ?? '');
            $driver = config('communication.channels.sms.driver');

            // Dispatch to the configured SMS driver
            $result = match ($driver) {
                'twilio' => $this->sendViaTwilio($phone, $body),
                'vonage' => $this->sendViaVonage($phone, $body),
                'africastalking' => $this->sendViaAfricasTalking($phone, $body),
                default => ['success' => false, 'error' => "Unknown SMS driver: {$driver}"],
            };

            return $result;

        } catch (\Throwable $e) {
            Log::error('Communication: SMS failed', [
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

    // ── Driver implementations (extend as needed) ──────────

    /** NOTE: unimplemented drivers must FAIL CLOSED (platform defect P-05: stubs previously returned success:true, silently faking delivery and emptying the retry backlog). */
    protected function sendViaTwilio(string $phone, string $body): array
    {
        // TODO: Implement Twilio integration
        // $twilio = new \Twilio\Rest\Client($sid, $token);
        // $twilio->messages->create($phone, ['from' => $from, 'body' => $body]);
        Log::error('Communication: Twilio driver not implemented — failing closed (P-05)');

        return ['success' => false, 'error' => 'driver_not_implemented'];
    }

    protected function sendViaVonage(string $phone, string $body): array
    {
        // TODO: Implement Vonage integration
        Log::error('Communication: Vonage driver not implemented — failing closed (P-05)');

        return ['success' => false, 'error' => 'driver_not_implemented'];
    }

    protected function sendViaAfricasTalking(string $phone, string $body): array
    {
        // TODO: Implement Africa's Talking integration
        Log::error('Communication: AfricasTalking driver not implemented — failing closed (P-05)');

        return ['success' => false, 'error' => 'driver_not_implemented'];
    }
}
