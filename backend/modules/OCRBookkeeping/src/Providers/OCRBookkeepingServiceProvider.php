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

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        // Register observers
        \App\Models\JournalEntry::observe(\App\Observers\JournalEntryObserver::class);
    }
}
