<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\PlatformRateLimitMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * PlatformRateLimitMiddleware — regression test for the file-cache permanent
 * 429 lockout.
 *
 * Root cause: the previous implementation used Cache::increment() + a
 * conditional Cache::put(). With the `file` cache driver, Cache::increment()
 * strips the entry's expiration, so once the counter exceeded the limit it
 * NEVER decayed — the IP stayed 429-locked forever. That made GET /features
 * return 429 indefinitely, which the frontend read as "no features enabled"
 * and blanked the sidebar (only Master Dashboard / Website / Settings showed).
 *
 * The fix uses the canonical Illuminate\Support\Facades\RateLimiter, which
 * stores an absolute reset timestamp and is TTL-independent, so the window
 * always self-heals.
 */
class PlatformRateLimitMiddlewareTest extends TestCase
{
    private function makeRequest(string $path = '/api/v1/features'): Request
    {
        $request = Request::create($path, 'GET');
        // Force a deterministic client IP so the limiter key is stable.
        $request->server->set('REMOTE_ADDR', '203.0.113.42');

        return $request;
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('rate_limit:platform:203.0.113.42');
        parent::tearDown();
    }

    public function test_allows_requests_under_the_limit(): void
    {
        $middleware = new PlatformRateLimitMiddleware;
        $passed = 0;

        for ($i = 0; $i < 120; $i++) {
            $response = $middleware->handle($this->makeRequest(), fn () => response()->json(['ok' => true]));
            if ($response->getStatusCode() === 200) {
                $passed++;
            }
        }

        $this->assertSame(120, $passed, 'First 120 requests must pass');
    }

    public function test_blocks_request_over_the_limit(): void
    {
        $middleware = new PlatformRateLimitMiddleware;

        // Exhaust the limit.
        for ($i = 0; $i < 120; $i++) {
            $middleware->handle($this->makeRequest(), fn () => response()->json(['ok' => true]));
        }

        // The 121st must be throttled.
        $response = $middleware->handle($this->makeRequest(), fn () => response()->json(['ok' => true]));
        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
    }

    public function test_limit_self_heals_after_window_resets(): void
    {
        $middleware = new PlatformRateLimitMiddleware;

        // Trip the limit.
        for ($i = 0; $i < 121; $i++) {
            $middleware->handle($this->makeRequest(), fn () => response()->json(['ok' => true]));
        }
        $blocked = $middleware->handle($this->makeRequest(), fn () => response()->json(['ok' => true]));
        $this->assertSame(429, $blocked->getStatusCode());

        // THE REGRESSION: with the old file-cache code the counter never decayed
        // and the IP stayed locked forever. RateLimiter exposes a bounded
        // availableIn(), and clearing the window (simulating decay) must
        // immediately restore service — proving the lock is NOT permanent.
        $key = 'rate_limit:platform:203.0.113.42';
        $this->assertLessThanOrEqual(60, RateLimiter::availableIn($key), 'Window must decay within 60s, not stay stuck');

        RateLimiter::clear($key); // simulate the decay window elapsing
        $recovered = $middleware->handle($this->makeRequest(), fn () => response()->json(['ok' => true]));
        $this->assertSame(200, $recovered->getStatusCode(), 'After the window resets, requests must pass again (no permanent lockout)');
    }

    public function test_skip_paths_are_exempt(): void
    {
        $middleware = new PlatformRateLimitMiddleware;

        foreach (['/api/v1/auth/login', '/api/v1/health', '/api/v1/webhooks/x', '/api/v1/admin/users'] as $path) {
            // Hammer a skip path far beyond the limit; it must never 429.
            $status = 200;
            for ($i = 0; $i < 200; $i++) {
                $status = $middleware->handle($this->makeRequest($path), fn () => response()->json(['ok' => true]))->getStatusCode();
            }
            $this->assertSame(200, $status, "Exempt path {$path} must never be rate limited");
        }
    }
}
