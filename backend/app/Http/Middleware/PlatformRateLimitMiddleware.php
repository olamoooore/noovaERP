<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class PlatformRateLimitMiddleware
{
    /**
     * Window for rate limiting in seconds (1 minute).
     */
    private const WINDOW_SECONDS = 60;

    /**
     * Paths exempt from platform rate limiting.
     *
     * Per Pillar 2 Part 2 §2.2: the original `/admin/api/` substring never
     * matched the real admin path `/api/v1/admin/...`, making the exemption
     * dead code. `/v1/admin/` matches the actual prefix in routes/api.php.
     *
     * `/api/v1/features` and `/api/v1/dashboard` are deliberately NOT exempt:
     * they are normal tenant endpoints. The fix for their burst behaviour is
     * the canonical RateLimiter below (which always carries a TTL), not an
     * exemption.
     */
    private const SKIP_PATHS = [
        '/api/v1/webhooks/',
        '/api/v1/health',
        '/api/v1/auth/',
        '/v1/admin/',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::SKIP_PATHS as $skipPath) {
            if (str_contains($request->getPathInfo(), $skipPath)) {
                return $next($request);
            }
        }

        // Use the canonical Laravel RateLimiter. Unlike the previous
        // hand-rolled Cache::increment() + conditional Cache::put(),
        // RateLimiter::attempt() sets the decay window TTL atomically and
        // reliably on EVERY cache driver — including the `file` driver, where
        // Cache::increment() silently drops the expiration and would otherwise
        // leave the counter stuck above the limit FOREVER (permanent 429).
        //
        // 2026-09-21 follow-up: the bucket used to be keyed by IP only, so
        // every user behind one office NAT shared 120 req/min and normal SPA
        // traffic (dashboard + features + menu + POS polling) tripped 429s for
        // everyone. Authenticated requests are now keyed by user id; only
        // guests share an IP bucket. This middleware runs in the `api` group,
        // BEFORE the route's `auth:sanctum`, so the Sanctum guard is resolved
        // on demand here (later middleware reuses the cached user).
        $user = null;
        try {
            $user = auth('sanctum')->user();
        } catch (\Throwable $e) {
            $user = null;
        }

        if ($user !== null) {
            $limit = max(1, (int) config('platform.rate_limit.authenticated', 300));
            $key = 'rate_limit:platform:user:'.$user->getAuthIdentifier();
            $scope = 'user';
        } else {
            $limit = max(1, (int) config('platform.rate_limit.guest', 120));
            $key = 'rate_limit:platform:ip:'.$request->ip();
            $scope = 'ip';
        }

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $seconds = RateLimiter::availableIn($key);

            Log::warning('Platform API rate limit exceeded', [
                'scope' => $scope,
                'ip' => $request->ip(),
                'path' => $request->getPathInfo(),
                'method' => $request->method(),
                'retry_after' => $seconds,
                'user_id' => $user?->getAuthIdentifier(),
            ]);

            return response()->json([
                'error' => 'Too many requests',
                'message' => 'Rate limit exceeded. Please try again later.',
                'retry_after' => $seconds,
            ], 429)->withHeaders([
                'Retry-After' => $seconds,
                'X-RateLimit-Limit' => $limit,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);

        $response = $next($request);

        // Use the header bag directly: $next() may return a StreamedResponse
        // (e.g. chatter attachment downloads) which has no withHeaders() method.
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $limit - RateLimiter::attempts($key)));

        return $response;
    }
}
