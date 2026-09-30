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

            if (! $tenant) {
                $tenant = $this->resolveTenantFromHost($request->getHost());
            }

            if (! $tenant && app()->environment('local')) {
                $tenant = \App\Models\Tenant::query()->orderBy('created_at')->first();

                if ($tenant) {
                    Log::warning('InitializeTenantForPublicAccess: using first tenant fallback in local environment for storefront route', [
                        'tenant_id' => $tenant->id,
                        'path' => $request->path(),
                        'host' => $request->getHost(),
                    ]);
                }
            }

            if (! $tenant) {
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
                'path' => $request->path(),
                'host' => $request->getHost(),
            ]);

            $this->tenancy->initialize($tenant);

            return $next($request);
        }

        $path = $request->path();

        // 1. Token-based public routes (invoice acceptance) resolve the tenant
        //    from the token itself — before domain resolution — because the
        //    token uniquely identifies the owning tenant while the link may be
        //    opened on any domain (e.g. the shared production domain or a
        //    different tenant's *.localhost host).
        if (preg_match('#^api/v1/invoice-accept/#', $path)) {
            $token = $request->route('token');
            if ($token) {
                $tenant = app(\App\Services\InvoiceEvidenceService::class)
                    ->resolveTenantForAcceptanceToken((string) $token);

                if ($tenant) {
                    $this->tenancy->initialize($tenant);

                    return $next($request);
                }
            }
        }

        // Delivery-order customer portal — same pattern as invoice-accept.
        if (preg_match('#^api/v1/do-accept/#', $path)) {
            $token = $request->route('token');
            if ($token) {
                $tenant = app(\Modules\Sales\Services\DeliveryOrderService::class)
                    ->resolveTenantForCustomerToken((string) $token);

                if ($tenant) {
                    $this->tenancy->initialize($tenant);

                    return $next($request);
                }
            }
        }

        // Built-in e-signature signing page — the token identifies the tenant.
        if (preg_match('#^api/v1/sign/#', $path)) {
            $token = $request->route('token');
            if ($token) {
                $tenant = app(\App\Services\EsignService::class)
                    ->resolveTenantForToken((string) $token);

                if ($tenant) {
                    $this->tenancy->initialize($tenant);

                    return $next($request);
                }
            }
        }

        // 2. For /w/{slug} routes, resolve tenant by finding which tenant owns the website.
        //    This takes priority over domain resolution because the website's tenant
        //    may differ from the domain's tenant (e.g. shared domain in multi-tenant setup).
        if (preg_match('#^w/([^/]+)#', $path, $m)) {
            $websiteSlug = $m[1];
            $tenant = $this->resolveTenantFromWebsiteSlug($websiteSlug, $request);
            if ($tenant) {
                $this->tenancy->initialize($tenant);

                return $next($request);
            }
        }

        // 3. Try domain-based resolution from Host header
        $tenant = $this->resolveTenantFromHost($request->getHost());
        if ($tenant) {
            $this->tenancy->initialize($tenant);

            return $next($request);
        }

        // 4. No tenant resolved — fail closed
        Log::warning('InitializeTenantForPublicAccess: No tenant could be resolved, rejecting request', [
            'host' => $request->getHost(),
            'path' => $path,
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

    /**
     * FR-PLAT-03 servability gate: platform subdomains (system-controlled
     * zone, dns_status verified at insert) always serve; custom domains
     * only serve once their TXT ownership proof succeeded.
     */
    private function domainServable(object $domain): bool
    {
        // Null-coalescing keeps pre-migration databases behaving exactly as
        // before (platform semantics) instead of failing closed everywhere;
        // once the onboarding columns exist the real values decide.
        if (($domain->type ?? 'platform') === 'platform') {
            return true;
        }

        return ($domain->dns_status ?? 'verified') === 'verified';
    }

    private function resolveTenantFromHost(?string $host): ?\App\Models\Tenant
    {
        if (! $host) {
            return null;
        }

        try {
            $domain = DB::table('public.domains')->where('domain', $host)->first();

            if ($domain && $this->domainServable($domain)) {
                $tenant = \App\Models\Tenant::find($domain->tenant_id);
                if ($tenant) {
                    Log::info('InitializeTenantForPublicAccess: Tenant from domain', [
                        'domain' => $host,
                        'tenant_id' => $tenant->id,
                    ]);

                    return $tenant;
                }
            } elseif ($domain) {
                // FR-PLAT-03: a custom domain row exists but is not yet
                // DNS-verified — fail closed (do NOT resolve, do NOT fall
                // through to another tenant), otherwise an unverified claim
                // could hijack a host the tenant does not control.
                Log::warning('InitializeTenantForPublicAccess: unverified custom domain rejected', [
                    'domain' => $host,
                    'dns_status' => $domain->dns_status,
                ]);

                return null;
            }

            // Try subdomain resolution: demo.localhost -> look for tenant with exact ID match
            $centralDomains = config('tenancy.central_domains', ['localhost']);
            foreach ($centralDomains as $centralDomain) {
                $centralDomain = trim($centralDomain);
                if ($host !== $centralDomain && str_ends_with($host, '.'.$centralDomain)) {
                    $subdomain = str_replace('.'.$centralDomain, '', $host);

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

    /**
     * Resolve tenant by finding which tenant owns a website with the given slug.
     * Iterates through all tenants and checks their website table.
     */
    private function resolveTenantFromWebsiteSlug(string $websiteSlug, Request $request): ?\App\Models\Tenant
    {
        $candidates = [];

        try {
            foreach (\App\Models\Tenant::all() as $tenant) {
                try {
                    $this->tenancy->initialize($tenant);
                    $website = \Modules\WebsiteBuilder\Models\Website::where('slug', $websiteSlug)->first();
                    if ($website) {
                        $candidates[] = ['tenant' => $tenant, 'updated_at' => $website->updated_at];
                    }
                } catch (\Exception $e) {
                    // This tenant doesn't have the website builder tables — skip
                    continue;
                }
            }
        } catch (\Exception $e) {
            Log::warning('InitializeTenantForPublicAccess: Website slug tenant resolution failed', [
                'website_slug' => $websiteSlug,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (count($candidates) === 0) {
            return null;
        }

        if (count($candidates) === 1) {
            Log::info('InitializeTenantForPublicAccess: Tenant from website slug', [
                'website_slug' => $websiteSlug,
                'tenant_id' => $candidates[0]['tenant']->id,
            ]);

            return $candidates[0]['tenant'];
        }

        // Ambiguous slug (two tenants own a website with the same slug — seen on
        // production 2026-09-22: 'acme-web' existed in both the legacy Acme
        // tenant and Acme Production, and creation-order iteration always
        // returned the stale one). Prefer the tenant that owns the request host,
        // otherwise the most recently updated website — deterministic, never
        // creation order.
        $hostTenant = $this->resolveTenantFromHost($request->getHost());
        if ($hostTenant) {
            foreach ($candidates as $candidate) {
                if ($candidate['tenant']->id === $hostTenant->id) {
                    Log::info('InitializeTenantForPublicAccess: ambiguous website slug resolved by host', [
                        'website_slug' => $websiteSlug,
                        'host' => $request->getHost(),
                        'tenant_id' => $hostTenant->id,
                    ]);

                    return $hostTenant;
                }
            }
        }

        usort($candidates, fn ($a, $b) => $b['updated_at'] <=> $a['updated_at']);

        Log::warning('InitializeTenantForPublicAccess: ambiguous website slug; using most recently updated website', [
            'website_slug' => $websiteSlug,
            'candidates' => array_map(fn ($c) => $c['tenant']->id, $candidates),
            'chosen' => $candidates[0]['tenant']->id,
        ]);

        return $candidates[0]['tenant'];
    }
}
