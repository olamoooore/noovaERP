<?php

namespace Modules\WebsiteBuilder\Providers;

use Illuminate\Support\ServiceProvider;

class WebsiteBuilderServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations/tenant');
        // Routes are loaded via ModuleManager in routes/tenant.php with proper tenant context
        // $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'website-builder');
    }
}
