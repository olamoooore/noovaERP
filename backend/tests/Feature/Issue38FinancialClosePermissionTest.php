<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\TenantTestCase;

/**
 * Runtime tests for issue #38 — Protect all Financial Close mutation routes
 * with write permissions.
 *
 * Verifies the live router attaches the correct write-tier permission
 * middleware to every non-GET Financial Close route, and that no
 * duplicate unprefixed mutation alias carries a weaker middleware.
 */
class Issue38FinancialClosePermissionTest extends TenantTestCase
{
    public function test_all_financial_close_write_routes_carry_permission_middleware(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/close')
                || str_starts_with($route->uri(), 'api/v1/cycles')
                || str_starts_with($route->uri(), 'api/v1/tasks')
                || str_starts_with($route->uri(), 'api/v1/findings')
                || str_starts_with($route->uri(), 'api/v1/escalations')
                || str_starts_with($route->uri(), 'api/v1/reviews')
                || str_starts_with($route->uri(), 'api/v1/checklist-templates')
                || str_starts_with($route->uri(), 'api/v1/signoffs')
                || str_starts_with($route->uri(), 'api/v1/readiness'))
            ->filter(fn ($route) => in_array('POST', $route->methods())
                || in_array('PUT', $route->methods())
                || in_array('DELETE', $route->methods())
                || in_array('PATCH', $route->methods()));

        $this->assertGreaterThan(0, $routes->count(), 'No FinancialClose mutation routes found — test setup is stale.');

        $unprotected = [];
        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $hasPermission = collect($middleware)->contains(
                fn ($m) => str_starts_with($m, 'App\\Http\\Middleware\\RequirePermission')
            );

            if (! $hasPermission) {
                $unprotected[] = $route->methods()[0].' /'.$route->uri();
            }
        }

        $this->assertSame(
            [],
            $unprotected,
            "FinancialClose mutation routes missing write-tier permission middleware: ".
            implode(', ', $unprotected)
        );
    }

    public function test_no_financial_close_mutation_route_uses_accounting_close_read_alone(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/close')
                || str_starts_with($route->uri(), 'api/v1/cycles')
                || str_starts_with($route->uri(), 'api/v1/tasks')
                || str_starts_with($route->uri(), 'api/v1/findings')
                || str_starts_with($route->uri(), 'api/v1/escalations')
                || str_starts_with($route->uri(), 'api/v1/reviews')
                || str_starts_with($route->uri(), 'api/v1/checklist-templates')
                || str_starts_with($route->uri(), 'api/v1/signoffs')
                || str_starts_with($route->uri(), 'api/v1/readiness'))
            ->filter(fn ($route) => in_array('POST', $route->methods())
                || in_array('PUT', $route->methods())
                || in_array('DELETE', $route->methods())
                || in_array('PATCH', $route->methods()));

        $regressions = [];
        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $hasReadOnly = collect($middleware)->contains(
                fn ($m) => $m === 'App\\Http\\Middleware\\RequirePermission:accounting.close.read'
            );
            $hasAnyWrite = collect($middleware)->contains(
                fn ($m) => str_starts_with($m, 'App\\Http\\Middleware\\RequirePermission')
                    && $m !== 'App\\Http\\Middleware\\RequirePermission:accounting.close.read'
            );

            if ($hasReadOnly && ! $hasAnyWrite) {
                $regressions[] = $route->methods()[0].' /'.$route->uri();
            }
        }

        $this->assertSame(
            [],
            $regressions,
            "FinancialClose mutation routes still gated only by read permission: ".
            implode(', ', $regressions)
        );
    }
}
