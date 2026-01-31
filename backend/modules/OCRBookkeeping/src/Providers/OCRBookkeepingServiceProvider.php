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
        // Load routes with API v1 prefix for tenant context (without forcing auth middleware)
        Route::middleware(['api'])
            ->prefix('api/v1')
            ->group(__DIR__ . '/../../routes/api.php');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        // Register observers
        \App\Models\JournalEntry::observe(\App\Observers\JournalEntryObserver::class);
    }
}
