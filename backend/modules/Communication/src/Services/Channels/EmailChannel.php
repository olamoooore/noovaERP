<?php

namespace Modules\Communication\Services\Channels;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Communication\Models\CommunicationMessage;
use Modules\Communication\Models\CommunicationOutbox;

/**
 * EmailChannel — Sends messages via email (SMTP, SES, Mailgun, SendGrid).
 *
 * Uses Laravel's Mail facade with the tenant's configured SMTP settings.
 */
class EmailChannel implements ChannelInterface
{
    public function getChannel(): string
    {
        return 'email';
    }

    public function isEnabled(): bool
    {
        return config('communication.channels.email.enabled', true);
    }

    public function send(CommunicationOutbox $outbox, CommunicationMessage $message): array
    {
        try {
            $to = $outbox->recipient_email;
            if (! $to) {
                return ['success' => false, 'error' => 'No recipient email address'];
            }

            $subject = $message->subject ?? 'Notification';
            $htmlBody = $message->body ?? '';
            $plainBody = $message->body_plain ?? strip_tags($htmlBody);

            Mail::html($htmlBody, function ($mail) use ($to, $subject, $message) {
                $mail->to($to)
                    ->subject($subject);

                // CC / BCC from message
                if ($message->email_cc) {
                    $ccAddresses = array_filter(array_map('trim', explode(',', $message->email_cc)));
                    if (! empty($ccAddresses)) {
                        $mail->cc($ccAddresses);
                    }
                }

                if ($message->email_bcc) {
                    $bccAddresses = array_filter(array_map('trim', explode(',', $message->email_bcc)));
                    if (! empty($bccAddresses)) {
                        $mail->bcc($bccAddresses);
                    }
                }

                if ($message->email_from) {
                    $mail->from($message->email_from);
                }
            });

            Log::info('Communication: Email sent', [
                'outbox_id' => $outbox->id,
                'to' => $to,
                'subject' => $subject,
            ]);

            return ['success' => true];

        } catch (\Throwable $e) {
            Log::error('Communication: Email failed', [
                'outbox_id' => $outbox->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
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
