<?php

namespace Modules\Ecommerce\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Ecommerce\Console\Commands\ProcessAllWebOrders;

class EcommerceServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessAllWebOrders::class,
            ]);
        }
    }
}
