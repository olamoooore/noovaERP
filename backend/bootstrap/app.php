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
        \App\Providers\ActivityLoggingServiceProvider::class,
        \App\Platform\PlatformServiceProvider::class,
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

            Route::middleware('api')
                ->group(base_path('routes/whatsapp-webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'entitlement' => \App\Http\Middleware\EnsureEntitlementAccess::class,
            // Legacy alias kept for backwards-compatible route middleware strings like feature:pos
            'feature' => \App\Http\Middleware\EnsureEntitlementAccess::class,
            'auth.tenant_key' => \App\Http\Middleware\AuthenticateTenantApiKey::class,
            'auth.agent' => \App\Http\Middleware\AuthenticateAgent::class,
            'auth.agent_or_user' => \App\Http\Middleware\AuthenticateAgentOrUser::class,
            'ensure.tenant.search_path' => \App\Http\Middleware\EnsureTenantSearchPath::class,
            'inventory.access' => \App\Http\Middleware\EnsureInventoryAccess::class,
            'correlation.id' => \App\Http\Middleware\CorrelationIdMiddleware::class,
        ]);

        // Ensure auth.agent_or_user runs before SetTenantFromUserPreference
        $middleware->priority([
            \App\Http\Middleware\AuthenticateAgentOrUser::class,
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
        ]);

        // Attach correlation ID middleware to all API requests
        $middleware->appendToGroup('api', \App\Http\Middleware\CorrelationIdMiddleware::class);

        // Remove SubstituteBindings from the 'api' group so tenant routes can
        // add it AFTER the search_path middleware — otherwise route-model
        // binding queries run before the tenant schema is active.
        $middleware->removeFromGroup('api', \Illuminate\Routing\Middleware\SubstituteBindings::class);

        // Add CORS to the very beginning of global middleware stack
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        // Add platform API rate limiting middleware
        $middleware->appendToGroup('api', \App\Http\Middleware\PlatformRateLimitMiddleware::class);

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
        // API-first exception handling: return JSON for all exceptions
        $exceptions->renderable(function (\Throwable $e, $request) {
            if ($request->is('api/*')) {
                // Map common framework exceptions to their correct HTTP status
                // (these don't expose getStatusCode(), so the previous default
                // of 500 was wrong — auth errors returned 500 instead of 401).
                $status = match (true) {
                    $e instanceof \Illuminate\Auth\AuthenticationException => 401,
                    $e instanceof \Illuminate\Auth\Access\AuthorizationException => 403,
                    $e instanceof \Illuminate\Validation\ValidationException => 422,
                    $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException => 404,
                    $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500,
                    default => 500,
                };

                $payload = [
                    'success' => false,
                    'message' => config('app.debug') ? $e->getMessage() : 'An error occurred',
                    'exception' => class_basename($e),
                ];

                // Include field-level errors for validation failures.
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    $payload['message'] = $e->getMessage();
                    $payload['errors'] = $e->errors();
                }

                return response()->json($payload, $status);
            }

            return null;
        });

        // Log all exceptions (except 404s in production)
        $exceptions->reportable(function (\Throwable $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                return;
            }

            try {
                $url = request()->url();
                $method = request()->method();
                $userId = auth()->id();
            } catch (\Throwable) {
                $url = 'CLI';
                $method = 'CLI';
                $userId = null;
            }

            \Illuminate\Support\Facades\Log::error('Unhandled exception', [
                'exception' => $e,
                'url' => $url,
                'method' => $method,
                'user_id' => $userId,
            ]);
        });
    })->create();
