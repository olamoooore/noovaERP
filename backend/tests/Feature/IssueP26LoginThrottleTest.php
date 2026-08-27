<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Pillar 2 Part 2 §2.3:
 *
 * The tenant-user login (`/api/v1/auth/login`) and web-shop customer
 * login (`/api/v1/web-shop/auth/login`) had no dedicated brute-force
 * throttle. Only the global 120/min IP rate limit applied, which is
 * not credential-guessing-appropriate. The admin login
 * (`/api/v1/admin/auth/login`) had throttle:5,1 — the same level
 * should apply to tenant-user and web-shop logins.
 */
class IssueP26LoginThrottleTest extends TestCase
{
    public function test_v1_auth_login_has_dedicated_brute_force_throttle(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->map(fn ($r) => (string) $r->uri())
            ->filter(fn ($uri) => $uri === 'api/v1/auth/login')
            ->values()
            ->all();

        $this->assertNotEmpty($routes, 'api/v1/auth/login must be registered');

        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->first(fn ($r) => (string) $r->uri() === 'api/v1/auth/login');

        $throttle = $this->extractThrottle($route);
        $this->assertSame(
            '5,1',
            $throttle,
            'api/v1/auth/login must have throttle:5,1 middleware (5 requests/minute/IP)'
        );
    }

    public function test_legacy_auth_login_has_dedicated_brute_force_throttle(): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->first(fn ($r) => (string) $r->uri() === 'api/auth/login');

        $throttle = $this->extractThrottle($route);
        $this->assertSame(
            '5,1',
            $throttle,
            'api/auth/login (legacy) must have throttle:5,1 middleware'
        );
    }

    public function test_v1_auth_signup_has_dedicated_brute_force_throttle(): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->first(fn ($r) => (string) $r->uri() === 'api/v1/auth/signup');

        $throttle = $this->extractThrottle($route);
        $this->assertSame(
            '5,1',
            $throttle,
            'api/v1/auth/signup must have throttle:5,1 (anti-account-creation abuse)'
        );
    }

    public function test_web_shop_login_has_dedicated_brute_force_throttle(): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->first(fn ($r) => (string) $r->uri() === 'api/v1/web-shop/auth/login');

        $throttle = $this->extractThrottle($route);
        $this->assertSame(
            '5,1',
            $throttle,
            'api/v1/web-shop/auth/login must have throttle:5,1 middleware'
        );
    }

    public function test_admin_login_still_has_throttle(): void
    {
        // Sanity check: the admin login already had throttle:5,1 per
        // the original Pillar 2 Part 2 §2.2 verification. This test
        // pins that we didn't break it when adding throttle to the
        // other login routes.
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->first(fn ($r) => str_contains((string) $r->uri(), 'admin/auth/login'));

        $throttle = $this->extractThrottle($route);
        $this->assertSame(
            '5,1',
            $throttle,
            'admin login must still have throttle:5,1 (regression check)'
        );
    }

    private function extractThrottle(\Illuminate\Routing\Route $route): ?string
    {
        $middleware = $route->gatherMiddleware();
        foreach ($middleware as $m) {
            if (is_string($m) && str_starts_with($m, 'throttle:')) {
                return substr($m, strlen('throttle:'));
            }
        }

        return null;
    }
}
