<?php

namespace Modules\Loyalty\Providers;

use Illuminate\Support\ServiceProvider;

class LoyaltyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Routes are loaded via ModuleManager in routes/tenant.php
        // with proper /api/v1 prefix and tenant middleware
        $this->registerMigrations();
    }

    protected function registerMigrations(): void
    {
        $migrationsPath = base_path('modules/Loyalty/database/migrations/tenant');
        
        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }
    }
}
