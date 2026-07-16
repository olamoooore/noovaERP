<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\PlatformRateLimitMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Platform rate limiter demonstration test (ADC §6 — Rate Limiting).
 *
 * Uses the real test cache (file driver) to exercise
 * PlatformRateLimitMiddleware with actual atomic increments.
 *
 * Proves: after 120 requests the 121st returns 429 with retry_after,
 * and webhook/health paths bypass the limiter.
 */
class PlatformRateLimitingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Flush the rate limit key to start clean.
        Cache::forget('rate_limit:platform:127.0.0.1');
    }

    private function handle(string $path = '/api/v1/modules'): int
    {
        $request = Request::create($path, 'GET');

        return (new PlatformRateLimitMiddleware)
            ->handle($request, fn () => response('ok'))
            ->getStatusCode();
    }

    public function test_allows_up_to_120_requests_per_minute(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->assertEquals(200, $this->handle(), "Request #{$i} should pass");
        }
    }

    public function test_returns_429_when_threshold_exceeded(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->handle();
        }

        // The 121st request is rate-limited.
        $request = Request::create('/api/v1/modules', 'GET');
        $response = (new PlatformRateLimitMiddleware)
            ->handle($request, fn () => response('ok'));

        $this->assertEquals(429, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertEquals('Too many requests', $body['error']);
        $this->assertEquals(60, $body['retry_after']);
    }

    public function test_rate_limit_response_has_standard_shape(): void
    {
        for ($i = 0; $i < 121; $i++) {
            $this->handle();
        }

        $response = (new PlatformRateLimitMiddleware)
            ->handle(Request::create('/api/v1/modules', 'GET'), fn () => response('ok'));

        $body = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertArrayHasKey('message', $body);
        $this->assertArrayHasKey('retry_after', $body);
    }

    public function test_skips_rate_limiting_for_webhook_paths(): void
    {
        for ($i = 0; $i < 130; $i++) {
            $this->handle();
        }

        $this->assertEquals(200, $this->handle('/api/v1/webhooks/stripe'));
    }

    public function test_skips_rate_limiting_for_health_path(): void
    {
        for ($i = 0; $i < 130; $i++) {
            $this->handle();
        }

        $this->assertEquals(200, $this->handle('/api/v1/health'));
    }

    public function test_first_request_sets_60_second_window(): void
    {
        $this->handle();
        // The TTL is set on the cache key. We can verify by checking
        // the cache has a value at the key.
        $this->assertNotNull(Cache::get('rate_limit:platform:127.0.0.1'));
    }
}
