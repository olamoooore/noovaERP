<?php

namespace App\Providers;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\PostingEngine;
use App\Accounting\Services\ActionEligibilityEvaluator;
use App\Accounting\Services\StateTransitionService;
use App\Accounting\Services\ReversalCorrectionEngine;
use App\Accounting\Services\PostingPolicyMapper;
use App\Accounting\Services\RevisionPolicyResolver;
use App\Accounting\Services\StructuredObservabilityService;
use App\Listeners\PreventDangerousMigrations;
use Illuminate\Support\Facades\Event;
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
        $this->app->register(CompliancePluginServiceProvider::class);

        // ADR-005 Accounting Domain Services
        $this->app->singleton(StateTransitionService::class);
        $this->app->singleton(ActionEligibilityEvaluator::class);
        $this->app->singleton(ReversalCorrectionEngine::class);
        $this->app->singleton(PostingPolicyMapper::class);
        $this->app->singleton(RevisionPolicyResolver::class);
        $this->app->singleton(StructuredObservabilityService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Use custom PersonalAccessToken model that always uses public schema
        Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

        // Log dangerous migrations (migrate:fresh, db:wipe) for audit trail
        Event::listen(\Illuminate\Console\Events\CommandFinished::class, PreventDangerousMigrations::class);

        // Factory resolver for module models
        \Illuminate\Database\Eloquent\Factories\Factory::guessFactoryNamesUsing(function (string $modelName) {
            // Modules\POS\Models\PosTerminal -> Database\Factories\Modules\POS\Models\PosTerminalFactory
            if (str_starts_with($modelName, 'Modules\\')) {
                return 'Database\\Factories\\Modules\\' . substr($modelName, strlen('Modules\\')) . 'Factory';
            }
            // App\Models\User -> Database\Factories\UserFactory (default Laravel convention)
            return 'Database\\Factories\\' . class_basename($modelName) . 'Factory';
        });

        // --- Superadmin RBAC gates ---
        Gate::define('manage-admin-users', fn (\App\Models\User $user) => $user->role === 'platform_super_admin'
        );

        Gate::define('run-bulk-operations', fn (\App\Models\User $user) => in_array($user->role, ['platform_super_admin', 'platform_ops'], true)
        );

        Gate::define('view-revenue-analytics', fn (\App\Models\User $user) => in_array($user->role, ['platform_super_admin', 'billing_ops', 'sales_ops'], true)
        );

        Gate::define('manage-collections', fn (\App\Models\User $user) => in_array($user->role, ['platform_super_admin', 'billing_ops'], true)
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
                    if (! empty($mail['from_address'])) {
                        config(['mail.from.address' => $mail['from_address']]);
                    }
                    if (! empty($mail['from_name'])) {
                        config(['mail.from.name' => $mail['from_name']]);
                    }
                }
            }
        } catch (\Exception $e) {
            // Fails silently if DB/Schema checks fail during early boot
        }

        // SMTP TLS certificate verification is opt-out via env var.
        // Per Pillar 2 Part 3 §1.4: the original code unconditionally
        // disabled certificate verification, which weakens transport
        // security (MITM risk on outbound mail). Now the disabling
        // requires an explicit env var, which is false by default
        // in production and true only in explicit dev/staging setups.
        $this->app->afterResolving('mail.manager', function ($manager) {
            if (! (bool) env('MAIL_DISABLE_SMTP_TLS_VERIFY', false)) {
                return;
            }
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
