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
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations/tenant');
        $this->loadRoutesFrom(__DIR__ . '/../../routes/api.php');
    }
}
