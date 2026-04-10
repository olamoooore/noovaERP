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
 * 1. From Host header (domain-based) via the domains table
 * 2. From subdomain (exact match only)
 * 3. From authenticated user preference (if token present)
 * 4. Fail closed with 404 if no tenant resolved
 */
class InitializeTenantForPublicAccess
{
    private const DEDICATED_STOREFRONT_TENANT_EMAIL = 'infinitikpop@noovaerp.com';

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
            $tenant = \App\Models\Tenant::query()
                ->where('email', self::DEDICATED_STOREFRONT_TENANT_EMAIL)
                ->first();

            if (!$tenant) {
                Log::warning('InitializeTenantForPublicAccess: Dedicated storefront tenant missing', [
                    'tenant_email' => self::DEDICATED_STOREFRONT_TENANT_EMAIL,
                    'path' => $request->path(),
                    'host' => $request->getHost(),
                ]);

                return response()->json([
                    'message' => 'Storefront tenant could not be resolved.',
                ], 404);
            }

            Log::info('InitializeTenantForPublicAccess: Dedicated storefront tenant resolved', [
                'tenant_id' => $tenant->id,
                'tenant_email' => $tenant->email,
                'path' => $request->path(),
                'host' => $request->getHost(),
            ]);

            $this->tenancy->initialize($tenant);

            return $next($request);
        }

        // 1. Try domain-based resolution from Host header
        $host = $request->getHost();
        
        if ($host) {
            try {
                $domain = DB::table('domains')->where('domain', $host)->first();

                if ($domain) {
                    $tenant = \App\Models\Tenant::find($domain->tenant_id);
                    if ($tenant) {
                        Log::info('InitializeTenantForPublicAccess: Tenant from domain', [
                            'domain' => $host,
                            'tenant_id' => $tenant->id,
                        ]);
                        $this->tenancy->initialize($tenant);
                        return $next($request);
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
                            $this->tenancy->initialize($tenant);
                            return $next($request);
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning('InitializeTenantForPublicAccess: Tenant resolution query failed', [
                    'host' => $host,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 2. Try from authenticated user (optional auth - don't fail if not authenticated)
        try {
            $user = $request->user('sanctum');
            if ($user && !($user->is_super_admin ?? false)) {
                $defaultCompany = DB::table('user_company_access')
                    ->where('user_id', $user->id)
                    ->where('is_default', true)
                    ->first();

                if ($defaultCompany) {
                    $tenant = \App\Models\Tenant::find($defaultCompany->tenant_id);
                    if ($tenant) {
                        Log::info('InitializeTenantForPublicAccess: Tenant from user preference', [
                            'user_id' => $user->id,
                            'tenant_id' => $tenant->id,
                        ]);
                        $this->tenancy->initialize($tenant);
                        return $next($request);
                    }
                }
            }
        } catch (\Exception $e) {
            // Auth failed silently - this is expected for public access
        }

        // 3. No tenant resolved — fail closed
        Log::warning('InitializeTenantForPublicAccess: No tenant could be resolved, rejecting request', [
            'host' => $request->getHost(),
        ]);

        return response()->json([
            'message' => 'Tenant could not be resolved.',
        ], 404);
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
}
