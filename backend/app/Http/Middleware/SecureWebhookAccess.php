<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * SecureWebhookAccess
 *
 * Hardening front door for payment gateway webhooks
 * (central route group, NO tenancy — see routes/webhooks.php).
 *
 * ACTUAL live-path ordering (Appendix C §C6), stated truthfully:
 *   1. rate limit per gateway+IP (this middleware)
 *   2. IP allowlist where the gateway publishes ranges (this middleware)
 *   3. gateway EVENT id extraction + replay lifecycle (this middleware:
 *      in-flight marker, done-on-2xx, release-on-failure — P-02)
 *   4. deterministic tenant resolution from the central lookup table
 *      (ResolveTenantFromWebhook — written at intent creation since P-01)
 *   5. signature verification with the TENANT's secret, inside the
 *      controller, BEFORE any state change (Stripe construct-event, HitPay
 *      HMAC, PayPal verify API with payment_gateways.config.webhook_id)
 *   6. at-most-once application via DB webhook_id/processed_at under row
 *      lock (PaymentWebhookController + PaymentTransactionCompletionService)
 *
 * Note on step ordering: the schema switch in step 4 happens on the
 * transaction id BEFORE signature verification (a documented platform
 * constraint — resolution data is the unforgeable gateway-issued
 * transaction id; no state change may occur before step 5, and none does).
 */
class SecureWebhookAccess
{
    /**
     * Gateway-specific IP ranges (for additional security layer)
     */
    private const GATEWAY_IP_RANGES = [
        'stripe' => [
            // Stripe webhook IPs: https://stripe.com/docs/ips
            '3.18.12.63',
            '3.130.192.231',
            '13.235.14.237',
            '13.235.122.149',
            '35.154.171.200',
            '52.15.183.149',
            '54.187.174.169',
            '54.187.216.72',
            '54.241.31.99',
            '54.241.31.102',
            '54.241.34.107',
        ],
        'hitpay' => [], // HitPay doesn't publish IP ranges
        'paypal' => [], // PayPal uses dynamic IPs
    ];

    /**
     * Maximum webhook requests per minute per gateway
     */
    private const RATE_LIMIT_PER_MINUTE = 60;

    public function handle(Request $request, Closure $next, string $gateway): Response
    {
        // 1. Rate limiting - prevent DoS attacks
        $clientIp = $request->ip();
        $rateLimitKey = "webhook_rate_limit:{$gateway}:{$clientIp}";

        // LATENT CRASH FIXED: the previous implementation called
        // Cache::expire() after Cache::increment(). Repository proxies
        // ->expire() to the underlying STORE, and only Redis/Memcached
        // stores implement it — on the file (and test array) driver EVERY
        // first delivery per key hit BadMethodCallException => HTTP 500 on
        // all webhooks. Use a get/put window that always carries a TTL.
        $attempts = (int) Cache::get($rateLimitKey, 0) + 1;
        Cache::put($rateLimitKey, $attempts, 60); // 1 minute window

        if ($attempts > self::RATE_LIMIT_PER_MINUTE) {
            Log::warning('Webhook rate limit exceeded', [
                'gateway' => $gateway,
                'ip' => $clientIp,
                'attempts' => $attempts,
            ]);

            return response()->json(['error' => 'Rate limit exceeded'], 429);
        }

        // 2. IP allowlisting (optional, only if configured for gateway)
        if ($this->shouldCheckIp($gateway) && ! $this->isAllowedIp($clientIp, $gateway)) {
            Log::warning('Webhook from unauthorized IP', [
                'gateway' => $gateway,
                'ip' => $clientIp,
            ]);

            return response()->json(['error' => 'Unauthorized IP address'], 403);
        }

        // 3. Extract and validate webhook payload structure
        $webhookId = $this->extractWebhookId($request, $gateway);
        if (! $webhookId) {
            Log::error('Webhook missing identifier', [
                'gateway' => $gateway,
                'headers' => $request->headers->all(),
            ]);

            return response()->json(['error' => 'Missing webhook identifier'], 400);
        }

        // 4. Replay attack prevention (P-02 fix).
        //
        // DEFECT HISTORY: the marker was written BEFORE signature
        // verification, was keyed on a PER-DELIVERY header (Stripe retries
        // and HitPay HMAC change every delivery → same event could process
        // twice), and was never promoted to "done" — so a legitimate retry
        // of a FAILED delivery was swallowed for 300 s while a replay AFTER
        // 300 s re-ran a completed event.
        //
        // Now (Appendix C §C6 rule 8): in-flight marker only for concurrent
        // duplicates; "done" is written ONLY on a 2xx outcome, keyed on the
        // gateway EVENT id; failures delete the marker so gateway retries
        // can re-run. Permanent dedup remains DB-side (webhook_id +
        // processed_at under row lock) — this cache is the fast front door.
        $replayKey = self::replayKey($gateway, $webhookId);
        $state = Cache::get($replayKey);

        if ($state === 'done') {
            Log::info('Duplicate webhook detected (already processed)', [
                'gateway' => $gateway,
                'webhook_id' => $webhookId,
            ]);

            // Return success to stop gateway retries, don't reprocess.
            return response()->json(['status' => 'already processed']);
        }

        if ($state === 'processing') {
            Log::info('Concurrent duplicate webhook ignored (in-flight)', [
                'gateway' => $gateway,
                'webhook_id' => $webhookId,
            ]);

            return response()->json(['status' => 'already processed']);
        }

        // 5. Store webhook_id + replay key for the controller.
        $request->attributes->set('webhook_id', $webhookId);
        $request->attributes->set('gateway', $gateway);
        $request->attributes->set('webhook_replay_key', $replayKey);

        // 6. Mark in-flight so concurrent deliveries don't double-run.
        Cache::put($replayKey, 'processing', 300); // 5 minutes to complete

        Log::info('Webhook security checks passed', [
            'gateway' => $gateway,
            'webhook_id' => $webhookId,
            'ip' => $clientIp,
        ]);

        $response = $next($request);

        // 7. Lifecycle (P-02): success → permanent window "done"; any
        //    non-2xx (signature failure, tenant 404, processing error) →
        //    release so the gateway's retry is genuinely retryable.
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            Cache::put($replayKey, 'done', 86400);
        } else {
            Cache::forget($replayKey);
        }

        return $response;
    }

    /**
     * Public for observability/tests: the dedupe cache key for a delivery.
     */
    public static function replayKey(string $gateway, string $eventId): string
    {
        return "webhook_processed:{$gateway}:{$eventId}";
    }

    /**
     * Extract the GATEWAY EVENT ID for dedupe (never per-delivery headers —
     * retry deliveries of one event must map to one key).
     */
    private function extractWebhookId(Request $request, string $gateway): ?string
    {
        $payload = $request->all();

        return match ($gateway) {
            'stripe' => $payload['id']
                ?? $request->header('Stripe-Signature')
                ?? null,
            'hitpay' => $payload['id']
                ?? $payload['payment_request_id']
                ?? $request->header('X-HitPay-Signature')
                ?? $payload['hmac']
                ?? null,
            'paypal' => $payload['id']
                ?? $request->header('PayPal-Transmission-Id')
                ?? $payload['resource']['id']
                ?? null,
            default => $payload['id']
                ?? $request->header('X-Webhook-ID')
                ?? $payload['webhook_id']
                ?? null,
        };
    }

    /**
     * Check if we should validate IP for this gateway
     */
    private function shouldCheckIp(string $gateway): bool
    {
        // Only check IP if specific ranges are configured
        return ! empty(self::GATEWAY_IP_RANGES[$gateway]);
    }

    /**
     * Check if IP is in allowed ranges
     */
    private function isAllowedIp(string $ip, string $gateway): bool
    {
        $allowedIps = self::GATEWAY_IP_RANGES[$gateway] ?? [];

        // Exact match
        if (in_array($ip, $allowedIps, true)) {
            return true;
        }

        // CIDR range check (for future use)
        foreach ($allowedIps as $allowedIp) {
            if (str_contains($allowedIp, '/')) {
                if ($this->ipInCidrRange($ip, $allowedIp)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if IP is within CIDR range
     */
    private function ipInCidrRange(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = ~((1 << (32 - (int) $mask)) - 1);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
