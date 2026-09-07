<?php

namespace Modules\AccountingFoundation\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\AccountingFoundation\Services\OpeningBalanceLifecycle;
use Modules\AccountingFoundation\Services\OpeningBalanceService;
use Modules\AccountingFoundation\Services\OpeningBalanceValidator;
use Modules\AccountingFoundation\Services\OpeningBalanceReconciler;

class AccountingFoundationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Register services in the container
        $this->app->singleton(OpeningBalanceLifecycle::class);
        $this->app->singleton(OpeningBalanceValidator::class);
        $this->app->singleton(OpeningBalanceReconciler::class);
        $this->app->singleton(OpeningBalanceService::class);

        // Routes — scoped under /api/v1/accounting-foundation
        Route::prefix('api/v1/accounting-foundation')
            ->middleware([
                'api',
                'auth:sanctum',
                \App\Http\Middleware\SetTenantFromUserPreference::class,
                \App\Http\Middleware\EnsureTenantSearchPath::class,
                \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ])
            ->group(function () {
                require __DIR__.'/../../routes/api.php';
            });
    }

    public function register(): void
    {
        //
    }
}
