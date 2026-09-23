<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\PlatformRateLimitMiddleware;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 2026-09-21 production issue: /api/v1/features and /api/v1/dashboard returned
 * 429 for everyone because the platform limiter was keyed by IP only — every
 * user behind one office NAT shared 120 req/min. Authenticated requests are
 * now keyed per user (guests stay per-IP).
 *
 * The middleware is exercised directly (`handle()` with a real Request and a
 * pass-through `$next`) against an isolated array cache; TenantTestCase strips
 * this middleware globally, so an HTTP probe route cannot be used.
 */
class PlatformRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate limiter state from any other store (file cache persists
        // across runs and would make these assertions order-dependent).
        config(['cache.default' => 'array']);
    }

    private function hit(): \Symfony\Component\HttpFoundation\Response
    {
        return app(PlatformRateLimitMiddleware::class)
            ->handle(Request::create('/api/v1/features', 'GET'), fn () => response()->json(['ok' => true]));
    }

    public function test_authenticated_requests_are_keyed_per_user(): void
    {
        config(['platform.rate_limit.authenticated' => 3]);

        $userA = new User;
        $userA->id = 101;
        $userB = new User;
        $userB->id = 202;

        RateLimiter::clear('rate_limit:platform:user:101');
        RateLimiter::clear('rate_limit:platform:user:202');

        Sanctum::actingAs($userA);
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->hit()->getStatusCode());
        }
        $limited = $this->hit();
        $this->assertSame(429, $limited->getStatusCode());
        $this->assertSame(3, (int) $limited->headers->get('X-RateLimit-Limit'));

        // A different user has an independent bucket (same IP).
        Sanctum::actingAs($userB);
        $this->assertSame(200, $this->hit()->getStatusCode());
    }

    public function test_guest_requests_are_keyed_per_ip(): void
    {
        config(['platform.rate_limit.guest' => 2]);
        RateLimiter::clear('rate_limit:platform:ip:127.0.0.1');

        $this->assertSame(200, $this->hit()->getStatusCode());
        $this->assertSame(200, $this->hit()->getStatusCode());
        $limited = $this->hit();
        $this->assertSame(429, $limited->getStatusCode());
        $this->assertSame(2, (int) $limited->headers->get('X-RateLimit-Limit'));
    }
}
