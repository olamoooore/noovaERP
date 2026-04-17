<?php

namespace Modules\POS\Providers;

use Illuminate\Support\ServiceProvider;

class POSServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
