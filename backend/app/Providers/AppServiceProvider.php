<?php

namespace App\Providers;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\PostingEngine;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PostingEngineInterface::class, PostingEngine::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Use custom PersonalAccessToken model that always uses public schema
        Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

        // --- Superadmin RBAC gates ---
        Gate::define('manage-admin-users', fn(\App\Models\User $user) =>
            $user->role === 'platform_super_admin'
        );

        Gate::define('run-bulk-operations', fn(\App\Models\User $user) =>
            in_array($user->role, ['platform_super_admin', 'platform_ops'], true)
        );

        Gate::define('view-revenue-analytics', fn(\App\Models\User $user) =>
            in_array($user->role, ['platform_super_admin', 'billing_ops', 'sales_ops'], true)
        );

        Gate::define('manage-collections', fn(\App\Models\User $user) =>
            in_array($user->role, ['platform_super_admin', 'billing_ops'], true)
        );

        // Load Mail Configuration from Database
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('settings')) {
                $mail = \App\Models\Setting::getValue('mail_config');

                if ($mail && is_array($mail)) {
                    $config = [
                        'transport' => $mail['driver'] ?? 'smtp',
                        'host' => $mail['host'] ?? config('mail.mailers.smtp.host'),
                        'port' => $mail['port'] ?? config('mail.mailers.smtp.port'),
                        'encryption' => $mail['encryption'] ?? config('mail.mailers.smtp.encryption'),
                        'username' => $mail['username'] ?? config('mail.mailers.smtp.username'),
                        'password' => $mail['password'] ?? config('mail.mailers.smtp.password'),
                        'timeout' => null,
                        'local_domain' => env('MAIL_EHLO_DOMAIN'),
                    ];
                    
                    // Update SMTP config
                    config(['mail.mailers.smtp' => $config]);

                    // Update From Address
                    if (!empty($mail['from_address'])) {
                        config(['mail.from.address' => $mail['from_address']]);
                    }
                    if (!empty($mail['from_name'])) {
                        config(['mail.from.name' => $mail['from_name']]);
                    }
                }
            }
        } catch (\Exception $e) {
            // Fails silently if DB/Schema checks fail during early boot
        }

        // Disable SSL certificate verification for SMTP (self-signed mail server cert)
        // This runs for both HTTP requests and queue workers
        $this->app->afterResolving('mail.manager', function ($manager) {
            try {
                $transport = $manager->mailer('smtp')->getSymfonyTransport();
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
            } catch (\Exception $e) {
                // Fails silently if transport is not SMTP
            }
        });
    }
}
