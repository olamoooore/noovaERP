<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Stancl\Tenancy\Facades\Tenancy;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveTenantFromWebhook
 *
 * Efficiently resolves tenant context from webhook payloads
 * without cross-tenant enumeration.
 *
 * Workstream 2 (2026-04-15): Addresses critical security issue where
 * webhooks scanned all tenants to find transactions. Now uses cache-based
 * lookups and transaction ID hashing for efficient resolution.
 *
 * Strategy:
 *   1. Extract the canonical local transaction ID from the webhook payload
 *   2. Look up tenant ID from cache (if previously resolved)
 *   3. If not cached, query the shared central webhook lookup table
 *   4. Cache the result for future webhooks
 *   5. Initialize tenancy for the resolved tenant
 *
 * This approach prevents:
 *   - Cross-tenant data leakage
 *   - Enumeration attacks (no scanning all tenants)
 *   - Performance issues with large tenant counts
 */
class ResolveTenantFromWebhook
{
    /**
     * Cache TTL for tenant lookups (hours)
     */
    private const TENANT_CACHE_TTL = 24;

    public function handle(Request $request, Closure $next, string $gateway): Response
    {
        // Extract transaction ID from payload (different for each gateway)
        $transactionId = $this->extractTransactionId($request, $gateway);

        if (!$transactionId) {
            Log::error('Cannot resolve tenant: missing transaction ID', [
                'gateway' => $gateway,
            ]);
            return response()->json(['error' => 'Missing transaction identifier'], 400);
        }

        // Try to get tenant from cache first
        $cacheKey = "webhook_tenant:{$gateway}:{$transactionId}";
        $tenantId = Cache::get($cacheKey);

        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if ($tenant) {
                Log::debug('Tenant resolved from cache', [
                    'gateway' => $gateway,
                    'transaction_id' => $transactionId,
                    'tenant_id' => $tenantId,
                ]);
                return $this->runInTenantContext($tenant, $request, $next);
            }
            // Tenant was deleted, clear cache and continue to lookup
            Cache::forget($cacheKey);
        }

        // Lookup tenant from central database
        $tenant = $this->lookupTenantByTransaction($transactionId, $gateway);

        if (!$tenant) {
            Log::error('Transaction not found', [
                'gateway' => $gateway,
                'transaction_id' => $transactionId,
            ]);
            return response()->json(['error' => 'Transaction not found'], 404);
        }

        // Cache the result for future webhooks
        Cache::put($cacheKey, $tenant->id, now()->addHours(self::TENANT_CACHE_TTL));

        Log::info('Tenant resolved from database', [
            'gateway' => $gateway,
            'transaction_id' => $transactionId,
            'tenant_id' => $tenant->id,
        ]);

        return $this->runInTenantContext($tenant, $request, $next);
    }

    /**
     * Extract transaction ID from webhook payload
     */
    private function extractTransactionId(Request $request, string $gateway): ?string
    {
        $payload = $request->all();

        return match ($gateway) {
            'stripe' => $payload['data']['object']['payment_intent']
                ?? $payload['data']['object']['id']
                ?? null,
            'hitpay' => $request->input('payment_request_id')
                ?? $request->input('id')
                ?? $request->input('reference_number')
                ?? null,
            'paypal' => $request->input('resource.supplementary_data.related_ids.order_id')
                ?? $request->input('resource.id')
                ?? null,
            default => null,
        };
    }

    /**
     * Lookup tenant by transaction ID
     *
     * Queries the shared central lookup table which maps each gateway
     * transaction ID to the owning tenant.
     */
    private function lookupTenantByTransaction(string $transactionId, string $gateway): ?Tenant
    {
        try {
            // payment_webhook_tenant_lookups is stored in the shared central schema.
            $result = DB::table(PaymentTransaction::webhookTenantLookupTable())
                ->where('transaction_id', $transactionId)
                ->where('gateway_name', $gateway)
                ->first(['tenant_id']);

            if (!$result || !$result->tenant_id) {
                return null;
            }

            return Tenant::find($result->tenant_id);

        } catch (\Exception $e) {
            Log::error('Failed to lookup tenant by transaction', [
                'transaction_id' => $transactionId,
                'gateway' => $gateway,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Run request in tenant context
     */
    private function runInTenantContext(Tenant $tenant, Request $request, Closure $next): Response
    {
        // Store tenant in request for controller access
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('tenant_id', $tenant->id);

        // Initialize tenancy
        return Tenancy::run($tenant, function () use ($request, $next) {
            return $next($request);
        });
    }
}
