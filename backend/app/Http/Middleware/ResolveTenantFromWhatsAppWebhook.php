<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\WhatsApp\Models\WhatsAppApiKey;
use Stancl\Tenancy\Facades\Tenancy;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantFromWhatsAppWebhook
{
    private const TENANT_CACHE_TTL = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $apiKeyValue = $request->header('x-api-key');

        if (!$apiKeyValue) {
            return response()->json(['error' => 'Missing API key'], 401);
        }

        $keyHash = hash('sha256', $apiKeyValue);
        $cacheKey = "whatsapp_webhook_tenant:{$keyHash}";
        $tenantId = Cache::get($cacheKey);

        if ($tenantId) {
            $tenant = Tenant::find($tenantId);

            if ($tenant) {
                return $this->runInTenantContext($tenant, $request, $next);
            }

            Cache::forget($cacheKey);
        }

        $tenant = $this->lookupTenantByApiKeyHash($keyHash);

        if (!$tenant) {
            Log::warning('WhatsApp webhook tenant not found', ['key_hash' => $keyHash]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        Cache::put($cacheKey, $tenant->id, now()->addHours(self::TENANT_CACHE_TTL));

        return $this->runInTenantContext($tenant, $request, $next);
    }

    private function lookupTenantByApiKeyHash(string $keyHash): ?Tenant
    {
        try {
            $result = DB::table(WhatsAppApiKey::webhookTenantLookupTable())
                ->where('key_hash', $keyHash)
                ->first(['tenant_id']);

            if (!$result || !$result->tenant_id) {
                return null;
            }

            return Tenant::find($result->tenant_id);
        } catch (\Throwable $e) {
            Log::error('Failed to resolve WhatsApp webhook tenant', [
                'key_hash' => $keyHash,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function runInTenantContext(Tenant $tenant, Request $request, Closure $next): Response
    {
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('tenant_id', $tenant->id);

        return Tenancy::run($tenant, function () use ($request, $next) {
            return $next($request);
        });
    }
}
