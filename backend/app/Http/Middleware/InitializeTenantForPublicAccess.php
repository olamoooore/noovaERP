<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Tenancy;

/**
 * Resolve tenant for public (unauthenticated) API routes.
 *
 * Resolution order:
 *   1. Configured storefront tenant (config key: tenancy.storefront_tenant_id or storefront_tenant_email)
 *   2. Host header domain lookup via the domains table
 *   3. Subdomain exact-match
 *   4. Fail closed with 404 if no tenant resolved
 *
 * ── Hardcoded tenant email REMOVED ──────────────────────────────────────────
 * The previous implementation keyed the dedicated storefront off a hardcoded
 * email address (`infinitikpop@noovaerp.com`). This violated tenant-agnostic
 * platform design: any tenant could become "the storefront" simply by changing
 * their email, and there was no way to configure a different storefront tenant
 * without editing code.
 *
 * Configuration:
 *   # config/tenancy.php (or .env)
 *   STOREFRONT_TENANT_ID=<uuid>           # preferred — directly addresses a tenant
 *   STOREFRONT_TENANT_EMAIL=<email>        # fallback when no ID is configured
 *
 * If neither is set, domain/subdomain resolution handles all public routes.
 */
class InitializeTenantForPublicAccess
{
    protected $tenancy;

    public function __construct(Tenancy $tenancy)
    {
        $this->tenancy = $tenancy;
    }

    public function handle(Request $request, Closure $next)
    {
        // Skip if tenancy is already initialized
        if (tenancy()->initialized) {
            return $next($request);
        }

        if ($this->isDedicatedStorefrontRoute($request)) {
            $tenant = $this->resolveStorefrontTenant();

            if (!$tenant) {
                $tenant = $this->resolveTenantFromHost($request->getHost());
            }

            if (!$tenant && app()->environment('local')) {
                $tenant = \App\Models\Tenant::query()->orderBy('created_at')->first();

                if ($tenant) {
                    Log::warning('InitializeTenantForPublicAccess: using first tenant fallback in local environment for storefront route', [
                        'tenant_id' => $tenant->id,
                        'path' => $request->path(),
                        'host' => $request->getHost(),
                    ]);
                }
            }

            if (!$tenant) {
                Log::warning('InitializeTenantForPublicAccess: storefront tenant not resolved — check STOREFRONT_TENANT_ID / STOREFRONT_TENANT_EMAIL in config', [
                    'path' => $request->path(),
                    'host' => $request->getHost(),
                ]);

                return response()->json([
                    'message' => 'Storefront tenant could not be resolved.',
                ], 404);
            }

            Log::info('InitializeTenantForPublicAccess: storefront tenant resolved', [
                'tenant_id' => $tenant->id,
                'path'      => $request->path(),
                'host'      => $request->getHost(),
            ]);

            $this->tenancy->initialize($tenant);

            return $next($request);
        }

        // 1. Try domain-based resolution from Host header
        $tenant = $this->resolveTenantFromHost($request->getHost());
        if ($tenant) {
            $this->tenancy->initialize($tenant);
            return $next($request);
        }

        // 2. No tenant resolved — fail closed
        Log::warning('InitializeTenantForPublicAccess: No tenant could be resolved, rejecting request', [
            'host' => $request->getHost(),
        ]);

        return response()->json([
            'message' => 'Tenant could not be resolved.',
        ], 404);
    }

    /**
     * Resolve the dedicated storefront tenant from config.
     *
     * Config priority:
     *   1. tenancy.storefront_tenant_id  (STOREFRONT_TENANT_ID in .env)
     *   2. tenancy.storefront_tenant_email (STOREFRONT_TENANT_EMAIL in .env)
     *
     * Returns null when neither config value is set — domain resolution handles
     * all public routes in that case.
     */
    private function resolveStorefrontTenant(): ?\App\Models\Tenant
    {
        // ── 1. Try direct tenant-ID lookup ────────────────────────────────────
        $tenantId = config('tenancy.storefront_tenant_id');
        if ($tenantId) {
            $tenant = \App\Models\Tenant::find($tenantId);
            if ($tenant) {
                return $tenant;
            }

            Log::warning('InitializeTenantForPublicAccess: STOREFRONT_TENANT_ID is set but no matching tenant found', [
                'storefront_tenant_id' => $tenantId,
            ]);
        }

        // ── 2. Fall back to email lookup ─────────────────────────────────────
        $email = config('tenancy.storefront_tenant_email');
        if ($email) {
            $tenant = \App\Models\Tenant::where('email', $email)->first();
            if ($tenant) {
                return $tenant;
            }

            Log::warning('InitializeTenantForPublicAccess: STOREFRONT_TENANT_EMAIL is set but no matching tenant found', [
                'storefront_tenant_email' => $email,
            ]);
        }

        // Neither config value is set — caller handles the null case
        return null;
    }

    private function isDedicatedStorefrontRoute(Request $request): bool
    {
        $path = ltrim($request->path(), '/');

        if (str_starts_with($path, 'api/v1/web-shop/')) {
            return true;
        }

        return str_starts_with($path, 'api/v1/website-public/products')
            || str_starts_with($path, 'api/v1/website-public/categories')
            || str_starts_with($path, 'api/v1/website-public/tags')
            || str_starts_with($path, 'api/v1/website-public/events');
    }

    private function resolveTenantFromHost(?string $host): ?\App\Models\Tenant
    {
        if (!$host) {
            return null;
        }

        try {
            $domain = DB::table('public.domains')->where('domain', $host)->first();

            if ($domain) {
                $tenant = \App\Models\Tenant::find($domain->tenant_id);
                if ($tenant) {
                    Log::info('InitializeTenantForPublicAccess: Tenant from domain', [
                        'domain' => $host,
                        'tenant_id' => $tenant->id,
                    ]);

                    return $tenant;
                }
            }

            // Try subdomain resolution: demo.localhost -> look for tenant with exact ID match
            $centralDomains = config('tenancy.central_domains', ['localhost']);
            foreach ($centralDomains as $centralDomain) {
                $centralDomain = trim($centralDomain);
                if ($host !== $centralDomain && str_ends_with($host, '.' . $centralDomain)) {
                    $subdomain = str_replace('.' . $centralDomain, '', $host);

                    // Only exact match — no partial/LIKE matching
                    $tenant = \App\Models\Tenant::find($subdomain);
                    if ($tenant) {
                        Log::info('InitializeTenantForPublicAccess: Tenant from subdomain', [
                            'subdomain' => $subdomain,
                            'tenant_id' => $tenant->id,
                        ]);

                        return $tenant;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('InitializeTenantForPublicAccess: Tenant resolution query failed', [
                'host' => $host,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
