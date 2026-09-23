<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Applies the outbound-mail configuration for the current context.
 *
 * Single source of truth for turning a stored `mail_config` record into
 * runtime mail configuration. Used by:
 *   - AppServiceProvider::boot()            (central/public schema at boot)
 *   - SetTenantSearchPath::loadTenantMailConfig()  (per-tenant request/job)
 *   - LoadMailConfig middleware             (request-level override)
 *
 * Supports two transports:
 *   - smtp    → host/port/username/password/encryption
 *   - resend  → HTTPS API, keyed by the tenant's Resend API key
 *
 * TLS certificate verification for SMTP is only disabled when
 * MAIL_DISABLE_SMTP_TLS_VERIFY is explicitly enabled (see Pillar 2 §1.4).
 */
class MailConfigLoader
{
    public const DRIVER_SMTP = 'smtp';

    public const DRIVER_RESEND = 'resend';

    public const DRIVERS = [self::DRIVER_SMTP, self::DRIVER_RESEND];

    /**
     * @param  array<string, mixed>|null  $mail  Raw `mail_config` value.
     */
    public function apply(?array $mail, bool $purgeMailer = false): void
    {
        if (! $mail || ! is_array($mail)) {
            return;
        }

        $driver = strtolower((string) ($mail['driver'] ?? self::DRIVER_SMTP));

        if ($driver === self::DRIVER_RESEND) {
            $this->applyResend($mail);
        } else {
            $this->applySmtp($mail);
        }

        if (! empty($mail['from_address'])) {
            config(['mail.from.address' => $mail['from_address']]);
        }
        if (! empty($mail['from_name'])) {
            config(['mail.from.name' => $mail['from_name']]);
        }

        if ($purgeMailer) {
            $this->purge($driver);
        }
    }

    private function applySmtp(array $mail): void
    {
        $config = [
            'transport' => 'smtp',
            'host' => $mail['host'] ?? env('MAIL_HOST', '127.0.0.1'),
            'port' => $mail['port'] ?? env('MAIL_PORT', 587),
            'encryption' => $mail['encryption'] ?? env('MAIL_ENCRYPTION', 'tls'),
            'username' => $mail['username'] ?? env('MAIL_USERNAME'),
            'password' => $mail['password'] ?? env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ];

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => $config,
        ]);
    }

    private function applyResend(array $mail): void
    {
        $key = $mail['api_key'] ?? $mail['key'] ?? null;

        if (empty($key)) {
            Log::warning('MailConfigLoader: resend driver selected without an API key');

            return;
        }

        config([
            'mail.default' => 'resend',
            'mail.mailers.resend.key' => $key,
            'services.resend.key' => $key,
        ]);
    }

    /**
     * Drop the resolved mailer so a newly-applied config takes effect, and
     * (for SMTP) mirror MAIL_DISABLE_SMTP_TLS_VERIFY onto the live transport.
     */
    public function purge(string $driver): void
    {
        if ($driver === self::DRIVER_RESEND) {
            app('mail.manager')->purge('resend');

            return;
        }

        try {
            app('mail.manager')->purge('smtp');

            if (! (bool) env('MAIL_DISABLE_SMTP_TLS_VERIFY', false)) {
                return;
            }

            $transport = app('mail.manager')->mailer('smtp')->getSymfonyTransport();
            if ($transport instanceof \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport) {
                $stream = $transport->getStream();
                if ($stream instanceof \Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream) {
                    $stream->setStreamOptions([
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true,
                        ],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('MailConfigLoader: failed to purge/configure smtp transport', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
