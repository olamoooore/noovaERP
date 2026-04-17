<?php

namespace Modules\POS\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\POS\Services\SessionStateMachineService;
use Modules\POS\Services\CartProjectionService;
use Modules\POS\Services\CartFocusService;
use Modules\POS\Services\MemberDisplayProfileService;
use Modules\POS\Services\PaymentStateService;
use Modules\POS\Services\MediaResolverService;
use Modules\POS\Services\DisplayPayloadBuilder;
use Modules\POS\Services\DisplayAuditService;
use Modules\POS\Services\DisplayPublisherService;

class POSServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SessionStateMachineService::class);
        $this->app->singleton(CartProjectionService::class);
        $this->app->singleton(CartFocusService::class);
        $this->app->singleton(MemberDisplayProfileService::class);
        $this->app->singleton(PaymentStateService::class);
        $this->app->singleton(MediaResolverService::class);
        $this->app->singleton(DisplayAuditService::class);
        $this->app->singleton(DisplayPublisherService::class);
        $this->app->singleton(DisplayPayloadBuilder::class, function ($app) {
            return new DisplayPayloadBuilder(
                $app->make(CartProjectionService::class),
                $app->make(CartFocusService::class),
                $app->make(MemberDisplayProfileService::class),
                $app->make(PaymentStateService::class),
                $app->make(MediaResolverService::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register module views
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'pos');
    }
}
