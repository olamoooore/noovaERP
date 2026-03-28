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
 * 2. From authenticated user preference (if token present)
 * 3. Fail gracefully if no tenant found
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

        // 0. Try explicit tenant ID from query parameter (e.g., ?tenant=xxx)
        // This is useful for preview URLs in development when Host header doesn't match
        $tenantParam = $request->query('tenant');
        if ($tenantParam) {
            $tenant = \App\Models\Tenant::find($tenantParam);
            if ($tenant) {
                Log::info('InitializeTenantForPublicAccess: Tenant from query param', [
                    'tenant_id' => $tenant->id,
                ]);
                $this->tenancy->initialize($tenant);
                return $next($request);
            }
        }

        // 1. Try domain-based resolution from Host header
        $host = $request->getHost();
        
        if ($host) {
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

            // Try subdomain resolution: demo.localhost -> look for domain "demo.localhost"
            // or tenant with matching subdomain
            $centralDomains = config('tenancy.central_domains', ['localhost']);
            foreach ($centralDomains as $centralDomain) {
                $centralDomain = trim($centralDomain);
                if ($host !== $centralDomain && str_ends_with($host, '.' . $centralDomain)) {
                    $subdomain = str_replace('.' . $centralDomain, '', $host);
                    
                    // Try finding tenant by ID matching subdomain
                    $tenant = \App\Models\Tenant::find($subdomain);
                    if ($tenant) {
                        Log::info('InitializeTenantForPublicAccess: Tenant from subdomain', [
                            'subdomain' => $subdomain,
                            'tenant_id' => $tenant->id,
                        ]);
                        $this->tenancy->initialize($tenant);
                        return $next($request);
                    }

                    // Try finding tenant by name matching subdomain
                    $tenant = \App\Models\Tenant::where('id', 'like', '%' . $subdomain . '%')->first();
                    if ($tenant) {
                        Log::info('InitializeTenantForPublicAccess: Tenant from subdomain partial match', [
                            'subdomain' => $subdomain,
                            'tenant_id' => $tenant->id,
                        ]);
                        $this->tenancy->initialize($tenant);
                        return $next($request);
                    }
                }
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

        // 3. No tenant resolved — log warning but don't fall back to Tenant::first()
        // as that causes cross-tenant data leakage
        if (!tenancy()->initialized) {
            Log::warning('InitializeTenantForPublicAccess: No tenant could be resolved', [
                'host' => $request->getHost(),
            ]);
        }

        return $next($request);
    }
}
