<?php

namespace Modules\OCRBookkeeping\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class OCRBookkeepingServiceProvider extends ServiceProvider
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
        // Routes are loaded via ModuleManager in tenant.php with proper tenant middleware.
        // Do NOT load routes here to avoid bypassing tenant context.

        // Do NOT also loadMigrationsFrom here or they will run during central migrations.
        // Tenant migrations are loaded via tenancy.php migration_parameters config
        // and explicitly via TenantTestCase::getTenantMigrationPaths() in tests.

        // Register observers
        \App\Models\JournalEntry::observe(\App\Observers\JournalEntryObserver::class);
    }
}
