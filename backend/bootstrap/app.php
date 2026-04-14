<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        \App\Providers\AppServiceProvider::class,
        \App\Providers\DatabaseQueryServiceProvider::class,
        \App\Providers\TenancyServiceProvider::class,
        \App\Providers\ModuleServiceProvider::class,
        \App\Providers\TenantDatabaseServiceProvider::class,
        \App\Providers\Filament\AdminPanelProvider::class,
    ])
    ->withEvents(discover: [
        __DIR__.'/../app/Listeners',
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Tenant routes (with tenancy middleware)
            Route::middleware('api')
                ->group(base_path('routes/tenant.php'));
            
            // Webhook routes (NO tenancy - works on any domain including ngrok)
            Route::middleware('api')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'feature' => \App\Http\Middleware\EnsureFeatureEnabled::class,
            'auth.tenant_key' => \App\Http\Middleware\AuthenticateTenantApiKey::class,
            'auth.agent' => \App\Http\Middleware\AuthenticateAgent::class,
            'auth.agent_or_user' => \App\Http\Middleware\AuthenticateAgentOrUser::class,
            'ensure.tenant.search_path' => \App\Http\Middleware\EnsureTenantSearchPath::class,
        ]);

        // Remove SubstituteBindings from the 'api' group so tenant routes can
        // add it AFTER the search_path middleware — otherwise route-model
        // binding queries run before the tenant schema is active.
        $middleware->removeFromGroup('api', \Illuminate\Routing\Middleware\SubstituteBindings::class);

        // Add CORS to the very beginning of global middleware stack
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        // This project is API-first (SPA + Sanctum). For API routes, we should
        // return a 401 instead of trying to redirect to a named 'login' route.
        $middleware->redirectGuestsTo(function ($request) {
            if ($request->is('api/*')) {
                return null;
            }

            return '/';
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
