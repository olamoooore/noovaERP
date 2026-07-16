<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RateLimitService
{
    /**
     * Default limits per endpoint type
     */
    protected array $limits = [
        'ai_inference' => ['requests' => 50, 'window_seconds' => 3600],
        'evidence_download' => ['requests' => 100, 'window_seconds' => 3600],
        'readiness_snapshot' => ['requests' => 30, 'window_seconds' => 3600],
        'default' => ['requests' => 200, 'window_seconds' => 3600],
    ];

    /**
     * Check if a request is within the rate limit
     */
    public function isAllowed(string $tenantId, string $userId, string $endpointType): bool
    {
        $key = $this->buildKey($tenantId, $userId, $endpointType);
        $limit = $this->getLimit($endpointType);

        $current = Cache::get($key, 0);

        if ($current >= $limit['requests']) {
            Log::warning('FinancialClose: rate limit exceeded', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'endpoint_type' => $endpointType,
                'current_count' => $current,
                'limit' => $limit['requests'],
            ]);

            return false;
        }

        return true;
    }

    /**
     * Increment the request count for a user/endpoint
     */
    public function increment(string $tenantId, string $userId, string $endpointType): void
    {
        $key = $this->buildKey($tenantId, $userId, $endpointType);
        $limit = $this->getLimit($endpointType);

        if (Cache::has($key)) {
            Cache::increment($key);
        } else {
            Cache::put($key, 1, $limit['window_seconds']);
        }
    }

    /**
     * Get remaining requests for a user/endpoint
     */
    public function getRemainingRequests(string $tenantId, string $userId, string $endpointType): int
    {
        $key = $this->buildKey($tenantId, $userId, $endpointType);
        $limit = $this->getLimit($endpointType);
        $current = Cache::get($key, 0);

        return max(0, $limit['requests'] - $current);
    }

    /**
     * Reset rate limit for a user/endpoint
     */
    public function reset(string $tenantId, string $userId, string $endpointType): void
    {
        $key = $this->buildKey($tenantId, $userId, $endpointType);
        Cache::forget($key);
    }

    /**
     * Build cache key
     */
    protected function buildKey(string $tenantId, string $userId, string $endpointType): string
    {
        return "fc_rate_limit:{$tenantId}:{$userId}:{$endpointType}";
    }

    /**
     * Get limit config for an endpoint type
     */
    protected function getLimit(string $endpointType): array
    {
        return $this->limits[$endpointType] ?? $this->limits['default'];
    }
}
