<?php

namespace Modules\Communication\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Communication\Console\ProcessOutboxCommand;
use Modules\Communication\Console\SeedTemplatesCommand;
use Modules\Communication\Jobs\CleanupOldMessages;
use Modules\Communication\Jobs\RetryFailedDeliveries;
use Modules\Communication\Jobs\SendDigest;
use Modules\Communication\Listeners\CommunicationEventSubscriber;
use Modules\Communication\Services\ChannelRouter;
use Modules\Communication\Services\Channels\EmailChannel;
use Modules\Communication\Services\Channels\InAppChannel;
use Modules\Communication\Services\Channels\PushChannel;
use Modules\Communication\Services\Channels\SmsChannel;
use Modules\Communication\Services\Channels\WhatsAppChannel;
use Modules\Communication\Services\CommunicationEngine;
use Modules\Communication\Services\TemplateRenderer;

class CommunicationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/communication.php', 'communication');

        // Register the Communication Engine as singleton
        $this->app->singleton(CommunicationEngine::class);
        $this->app->singleton(ChannelRouter::class);
        $this->app->singleton(TemplateRenderer::class);

        // Register channel adapters
        $this->app->singleton(EmailChannel::class);
        $this->app->singleton(InAppChannel::class);
        $this->app->singleton(SmsChannel::class);
        $this->app->singleton(WhatsAppChannel::class);
        $this->app->singleton(PushChannel::class);

        // Alias for convenience (Facade + app('communication'))
        $this->app->alias(CommunicationEngine::class, 'communication');
    }

    public function boot(): void
    {
        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        // Issue #85: register the inbound-email subtype so the IMAP bridge
        // (App\Services\InboundEmailService) can write system notes.
        \Modules\Communication\Support\EventSubtypeRegistry::register(
            'record.inbound_email',
        );
        // Do NOT also loadRoutesFrom here or routes will be registered twice
        // with different middleware stacks, bypassing tenant isolation.

        // Register Artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessOutboxCommand::class,
                SeedTemplatesCommand::class,
            ]);
        }

        // Register event subscriber
        Event::subscribe(CommunicationEventSubscriber::class);

        // Schedule recurring jobs
        $this->app->booted(function () {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);

            // Retry failed deliveries every 5 minutes
            $schedule->job(new RetryFailedDeliveries)->everyFiveMinutes();

            // Daily digest at 8am
            $schedule->job(new SendDigest('daily'))->dailyAt('08:00');

            // Weekly digest on Monday at 8am
            $schedule->job(new SendDigest('weekly'))->weeklyOn(1, '08:00');

            // Cleanup old messages daily at 3am
            $schedule->job(new CleanupOldMessages)->dailyAt('03:00');
        });
    }
}
