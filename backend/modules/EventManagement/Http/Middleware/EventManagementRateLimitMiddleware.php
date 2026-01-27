<?php

namespace Modules\EventManagement\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class EventManagementRateLimitMiddleware
{
    /**
     * Handle an incoming request.
     * Requirements: 7.3 - Rate limiting for public booking endpoints
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $limiter = 'default'): Response
    {
        $key = $this->resolveRequestSignature($request, $limiter);
        $maxAttempts = $this->getMaxAttempts($limiter);
        $decayMinutes = $this->getDecayMinutes($limiter);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return $this->buildRateLimitResponse($key, $maxAttempts);
        }

        RateLimiter::hit($key, $decayMinutes * 60);

        $response = $next($request);

        return $this->addHeaders(
            $response,
            $maxAttempts,
            RateLimiter::retriesLeft($key, $maxAttempts),
            RateLimiter::availableIn($key)
        );
    }

    /**
     * Resolve the request signature for rate limiting
     */
    protected function resolveRequestSignature(Request $request, string $limiter): string
    {
        $user = $request->user();
        $ip = $request->ip();
        $tenantId = $this->getTenantId($request);

        // Different rate limiting strategies based on limiter type
        switch ($limiter) {
            case 'admin_api':
                // Admin API: Rate limit by user ID
                return $user ? "admin_api:{$user->id}" : "admin_api:guest:{$ip}";
                
            case 'public_api':
                // Public API: Rate limit by IP and tenant
                return "public_api:{$tenantId}:{$ip}";
                
            case 'booking_creation':
                // Booking creation: Stricter limits by user/IP
                return $user 
                    ? "booking:{$user->id}:{$tenantId}" 
                    : "booking:guest:{$ip}:{$tenantId}";
                    
            case 'ticket_validation':
                // Ticket validation: Rate limit by IP
                return "ticket_validation:{$ip}";
                
            default:
                // Default: Rate limit by IP
                return "default:{$ip}";
        }
    }

    /**
     * Get maximum attempts for limiter type
     */
    protected function getMaxAttempts(string $limiter): int
    {
        $limits = [
            'admin_api' => 1000,        // 1000 requests per hour for admin
            'public_api' => 100,        // 100 requests per hour for public
            'booking_creation' => 10,   // 10 booking attempts per hour
            'ticket_validation' => 50,  // 50 ticket validations per hour
            'default' => 60,           // 60 requests per hour default
        ];

        return $limits[$limiter] ?? $limits['default'];
    }

    /**
     * Get decay minutes for limiter type
     */
    protected function getDecayMinutes(string $limiter): int
    {
        $decayTimes = [
            'admin_api' => 60,          // 1 hour window
            'public_api' => 60,         // 1 hour window
            'booking_creation' => 60,   // 1 hour window
            'ticket_validation' => 60,  // 1 hour window
            'default' => 60,           // 1 hour window
        ];

        return $decayTimes[$limiter] ?? $decayTimes['default'];
    }

    /**
     * Build rate limit exceeded response
     */
    protected function buildRateLimitResponse(string $key, int $maxAttempts): Response
    {
        $retryAfter = RateLimiter::availableIn($key);

        return response()->json([
            'error' => [
                'code' => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Too many requests. Please try again later.',
                'details' => [
                    'max_attempts' => $maxAttempts,
                    'retry_after_seconds' => $retryAfter,
                    'retry_after_minutes' => ceil($retryAfter / 60)
                ],
                'request_id' => request()->header('X-Request-ID', uniqid())
            ]
        ], Response::HTTP_TOO_MANY_REQUESTS, [
            'Retry-After' => $retryAfter,
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => 0,
        ]);
    }

    /**
     * Add rate limit headers to response
     */
    protected function addHeaders(Response $response, int $maxAttempts, int $remainingAttempts, int $retryAfter): Response
    {
        $response->headers->add([
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => max(0, $remainingAttempts),
        ]);

        if ($remainingAttempts <= 0) {
            $response->headers->add([
                'Retry-After' => $retryAfter,
            ]);
        }

        return $response;
    }

    /**
     * Get tenant ID from request
     */
    protected function getTenantId(Request $request): string
    {
        // Try multiple methods to extract tenant ID
        
        // Method 1: From header
        if ($tenantId = $request->header('X-Tenant-ID')) {
            return $tenantId;
        }
        
        // Method 2: From subdomain
        $host = $request->getHost();
        if (preg_match('/^([^.]+)\./', $host, $matches)) {
            return $matches[1];
        }
        
        // Method 3: From user context
        $user = $request->user();
        if ($user && isset($user->tenant_id)) {
            return $user->tenant_id;
        }
        
        // Method 4: From JWT token (if available)
        if ($token = $request->bearerToken()) {
            try {
                // This would need JWT library to decode
                // For now, return default
            } catch (\Exception $e) {
                // Ignore JWT parsing errors
            }
        }
        
        // Default tenant
        return 'default';
    }
}