<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

class LoadMailConfig
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Load custom settings
            $mail = Setting::getValue('mail_config');

            if ($mail && is_array($mail)) {
                // Determine driver
                $driver = $mail['driver'] ?? 'smtp';

                if ($driver === 'smtp') {
                    // Force SMTP configuration
                    Config::set('mail.default', 'smtp');
                    Config::set('mail.mailers.smtp.transport', 'smtp');
                    Config::set('mail.mailers.smtp.host', $mail['host'] ?? env('MAIL_HOST', 'smtp.mailgun.org'));
                    Config::set('mail.mailers.smtp.port', $mail['port'] ?? env('MAIL_PORT', 587));
                    Config::set('mail.mailers.smtp.encryption', $mail['encryption'] ?? env('MAIL_ENCRYPTION', 'tls'));
                    Config::set('mail.mailers.smtp.username', $mail['username'] ?? env('MAIL_USERNAME'));
                    Config::set('mail.mailers.smtp.password', $mail['password'] ?? env('MAIL_PASSWORD'));
                    Config::set('mail.mailers.smtp.timeout', null);
                    Config::set('mail.mailers.smtp.local_domain', env('MAIL_EHLO_DOMAIN'));
                }

                // Global "From" Address
                if (! empty($mail['from_address'])) {
                    Config::set('mail.from.address', $mail['from_address']);
                }
                if (! empty($mail['from_name'])) {
                    Config::set('mail.from.name', $mail['from_name']);
                }
            }

        } catch (\Throwable $e) {
            // Fails silently if database connection is not yet established
            // This prevents console/early-boot errors
        }

        return $next($request);
    }
}
