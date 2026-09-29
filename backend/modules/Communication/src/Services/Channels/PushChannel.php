<?php

namespace Modules\Communication\Services\Channels;

use Illuminate\Support\Facades\Log;
use Modules\Communication\Models\CommunicationMessage;
use Modules\Communication\Models\CommunicationOutbox;

/**
 * PushChannel — Sends push notifications via FCM or OneSignal.
 */
class PushChannel implements ChannelInterface
{
    public function getChannel(): string
    {
        return 'push';
    }

    public function isEnabled(): bool
    {
        return config('communication.channels.push.enabled', false);
    }

    public function send(CommunicationOutbox $outbox, CommunicationMessage $message): array
    {
        if (! $this->isEnabled()) {
            return ['success' => false, 'error' => 'Push channel is not enabled'];
        }

        try {
            $token = $outbox->recipient_device_token;
            if (! $token) {
                return ['success' => false, 'error' => 'No device token'];
            }

            $driver = config('communication.channels.push.driver');

            $result = match ($driver) {
                'fcm' => $this->sendViaFcm($token, $message),
                'onesignal' => $this->sendViaOneSignal($token, $message),
                default => ['success' => false, 'error' => "Unknown push driver: {$driver}"],
            };

            return $result;

        } catch (\Throwable $e) {
            Log::error('Communication: Push notification failed', [
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
    protected function sendViaFcm(string $token, CommunicationMessage $message): array
    {
        // TODO: Implement Firebase Cloud Messaging
        Log::error('Communication: FCM driver not implemented — failing closed (P-05)');

        return ['success' => false, 'error' => 'driver_not_implemented'];
    }

    protected function sendViaOneSignal(string $token, CommunicationMessage $message): array
    {
        // TODO: Implement OneSignal
        Log::error('Communication: OneSignal driver not implemented — failing closed (P-05)');

        return ['success' => false, 'error' => 'driver_not_implemented'];
    }
}
