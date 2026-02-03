<?php

namespace Modules\PaymentGateway\Providers;

use Illuminate\Support\ServiceProvider;

class PaymentGatewayServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // No config needed for now
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Only load migrations when tenancy is initialized
        // This prevents tenant-specific migrations from running during central migrations
        if (tenancy()->initialized) {
            $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        }

        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/../../routes/api.php');
    }
}
