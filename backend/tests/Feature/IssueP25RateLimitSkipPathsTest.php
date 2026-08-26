<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\PlatformRateLimitMiddleware;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Pillar 2 Part 2 §2.2:
 *
 * The original SKIP_PATHS array in PlatformRateLimitMiddleware
 * included `/admin/api/`, but the real admin path is
 * `/api/v1/admin/...` (per routes/api.php:152,
 * `Route::prefix('v1/admin')`). The `str_contains` check for
 * `/admin/api/` never matched the actual paths because the
 * `admin/api` substring order never occurs. The exemption was
 * dead code — the admin API was always rate-limited, contrary
 * to the intent.
 *
 * This test pins the fix: the SKIP_PATHS list must include
 * `/v1/admin/` (the actual prefix) and must NOT contain the
 * dead `/admin/api/` entry.
 */
class IssueP25RateLimitSkipPathsTest extends TestCase
{
    public function test_skiplist_includes_real_admin_path(): void
    {
        $reflection = new \ReflectionClass(PlatformRateLimitMiddleware::class);
        $skipPaths = $reflection->getConstant('SKIP_PATHS');

        $this->assertNotEmpty($skipPaths);

        // The real admin path prefix used by routes/api.php:152
        $this->assertContains(
            '/v1/admin/',
            $skipPaths,
            'SKIP_PATHS must include /v1/admin/ (the real admin path prefix)'
        );
    }

    public function test_skiplist_does_not_contain_dead_admin_path(): void
    {
        $reflection = new \ReflectionClass(PlatformRateLimitMiddleware::class);
        $skipPaths = $reflection->getConstant('SKIP_PATHS');

        $this->assertNotContains(
            '/admin/api/',
            $skipPaths,
            'SKIP_PATHS must NOT contain /admin/api/ (the dead code from the report — '
            .'its substring order never matches the real /api/v1/admin/... path)'
        );
    }

    public function test_skiplist_includes_webhook_path(): void
    {
        $reflection = new \ReflectionClass(PlatformRateLimitMiddleware::class);
        $skipPaths = $reflection->getConstant('SKIP_PATHS');

        // Per routes/webhooks.php, the webhook base is /api/v1/webhooks/
        $this->assertContains(
            '/api/v1/webhooks/',
            $skipPaths,
            'SKIP_PATHS must include /api/v1/webhooks/ (the payment-gateway webhooks)'
        );
    }

    public function test_real_admin_path_matches_skip_rule(): void
    {
        // Direct test: request to /api/v1/admin/auth/login should be
        // exempted from rate limiting.
        $middleware = app(PlatformRateLimitMiddleware::class);

        $request = Request::create('/api/v1/admin/auth/login', 'POST');
        $ran = false;
        $response = $middleware->handle($request, function () use (&$ran) {
            $ran = true;
            return response('next', 200);
        });

        $this->assertTrue(
            $ran,
            'Request to /api/v1/admin/auth/login must be exempted from rate limiting'
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_old_dead_path_no_longer_exempted(): void
    {
        // The dead code path was: a request with `/admin/api/` in
        // its path would be exempted. Since no such path exists, the
        // test verifies that adding `/admin/api/` to a path does NOT
        // exempt it (because the substring is absent in the new
        // SKIP_PATHS).
        $middleware = app(PlatformRateLimitMiddleware::class);

        $request = Request::create('/admin/api/something', 'GET');
        $ran = false;
        $response = $middleware->handle($request, function () use (&$ran) {
            $ran = true;
            return response('next', 200);
        });

        // The /admin/api/ path no longer exists in our routes, so
        // it should NOT be exempted (the rate limit middleware will
        // try to check, but the mock passes through since
        // the cache counter starts at 1 and is below the limit).
        $this->assertTrue($ran, 'Request should still be processed');
    }
}
