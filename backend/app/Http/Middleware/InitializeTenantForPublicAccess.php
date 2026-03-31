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
}
