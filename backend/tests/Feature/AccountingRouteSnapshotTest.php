<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccountingRouteSnapshotTest extends TestCase
{
    public function test_no_debug_or_test_closure_routes_in_accounting_api(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $debugRoutes = $routes->filter(function ($route) {
            $uri = $route->uri();

            return str_contains($uri, 'dashboard-test')
                || (str_contains($uri, 'accounting') && $route->getActionName() === 'Closure');
        });

        $this->assertCount(0, $debugRoutes, 'Debug/test closure routes found in accounting API: '.$debugRoutes->pluck('uri')->join(', '));
    }

    public function test_no_accounting_routes_point_to_missing_controller_methods(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $missing = [];

        foreach ($routes as $route) {
            $uri = $route->uri();
            if (! str_contains($uri, 'accounting') && ! str_contains($uri, 'payments') && ! str_contains($uri, 'invoices') && ! str_contains($uri, 'reports/financial')) {
                continue;
            }

            $action = $route->getActionName();
            if ($action === 'Closure' || ! str_contains($action, '@')) {
                continue;
            }

            [$controller, $method] = explode('@', $action);

            if (! class_exists($controller)) {
                continue;
            }

            if (! method_exists($controller, $method)) {
                $missing[] = "{$route->methods()[0]} {$route->uri()} → {$action}";
            }
        }

        $this->assertEmpty($missing, "Accounting routes point to missing controller methods:\n".implode("\n", $missing));
    }

    public function test_financial_report_routes_include_entity_middleware(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $reportRoutes = $routes->filter(function ($route) {
            return str_contains($route->uri(), 'reports/financial');
        });

        $this->assertNotEmpty($reportRoutes, 'No financial report routes found');

        foreach ($reportRoutes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains(
                \App\Http\Middleware\ResolveEntityContext::class,
                $middleware,
                "Route {$route->uri()} is missing ResolveEntityContext middleware"
            );
            $this->assertContains(
                \App\Http\Middleware\EnsureEntityAccess::class,
                $middleware,
                "Route {$route->uri()} is missing EnsureEntityAccess middleware"
            );
        }
    }

    public function test_import_routes_include_accounting_write_middleware(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $importRoutes = $routes->filter(function ($route) {
            $uri = $route->uri();

            return (str_ends_with($uri, '/import') || str_contains($uri, '/import/'))
                && str_contains($uri, 'api/v1/import');
        });

        $this->assertNotEmpty($importRoutes, 'No accounting import routes found');

        foreach ($importRoutes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains(
                \App\Http\Middleware\EnsureAccountingWriteAccess::class,
                $middleware,
                "Route {$route->uri()} is missing EnsureAccountingWriteAccess middleware"
            );
        }
    }

    public function test_only_supported_payment_update_route_is_exposed(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $paymentUpdateRoutes = $routes->filter(function ($route) {
            $uri = $route->uri();
            $methods = $route->methods();

            return str_contains($uri, 'payments')
                && ! str_contains($uri, 'bill-payments')
                && (in_array('PUT', $methods) || in_array('PATCH', $methods));
        });

        $this->assertCount(1, $paymentUpdateRoutes, 'Expected exactly one draft-payment update route: '.$paymentUpdateRoutes->pluck('uri')->join(', '));
        $route = $paymentUpdateRoutes->first();
        $this->assertSame('api/v1/payments/{payment}', $route->uri());
        $this->assertContains('PUT', $route->methods());
        $this->assertSame(
            \App\Http\Controllers\Api\PaymentController::class.'@update',
            $route->getActionName()
        );
    }

    public function test_no_logo_routes_exist(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $logoRoutes = $routes->filter(function ($route) {
            $uri = $route->uri();
            // Match exact "settings/logo" but not "receipt-settings/logo" or similar
            return preg_match('#(?:^|/)settings/logo(?:$|/)#', $uri);
        });

        $this->assertCount(0, $logoRoutes, 'Logo routes still registered despite missing controller methods');
    }
}
