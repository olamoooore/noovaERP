<?php

namespace Modules\Sales\Providers;

use Illuminate\Support\ServiceProvider;

class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            \Modules\Sales\Services\OrderLifecycleService::class,
            \Modules\Sales\Services\OrderLifecycleService::class,
        );

        $this->app->bind(
            \Modules\Sales\Services\SalesOrderService::class,
            \Modules\Sales\Services\SalesOrderService::class,
        );

        $this->app->bind(
            \Modules\Sales\Services\CreditCheckService::class,
            \Modules\Sales\Services\CreditCheckService::class,
        );
    }

    public function boot(): void {}
}
