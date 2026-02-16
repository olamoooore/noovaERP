<?php

namespace Modules\Core\Providers;

use Illuminate\Support\ServiceProvider;

use Illuminate\Support\Facades\Route;

class CoreServiceProvider extends ServiceProvider
{
    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        Route::prefix('api/v1')
            ->middleware('api')
            ->group(__DIR__ . '/../../routes/api.php');
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        //
    }
}
