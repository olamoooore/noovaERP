<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        \App\Providers\ModuleServiceProvider::class,
        \App\Providers\Filament\AdminPanelProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->group(base_path('routes/tenant.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'feature' => \App\Http\Middleware\EnsureFeatureEnabled::class,
            'auth.agent' => \App\Http\Middleware\AuthenticateAgent::class,
            'auth.agent_or_user' => \App\Http\Middleware\AuthenticateAgentOrUser::class,
        ]);

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
