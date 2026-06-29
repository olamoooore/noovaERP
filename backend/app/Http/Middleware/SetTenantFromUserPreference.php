<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Tenancy;

class SetTenantFromUserPreference
{
    protected $tenancy;

    public function __construct(Tenancy $tenancy)
    {
        $this->tenancy = $tenancy;
    }

    private function isValidUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        
        if (!$user) {
            return $next($request);
        }

        $resolvedTenant = null;

        if ($user->is_super_admin ?? false) {
            // Super-admins must explicitly select a tenant via X-Tenant-ID header
            $tenantId = $request->header('X-Tenant-ID');
            if (!$tenantId) {
                return response()->json([
                    'message' => 'Super-admin must specify X-Tenant-ID header to select a tenant context.',
                ], 400);
            }

            if (!$this->isValidUuid($tenantId)) {
                Log::warning('SetTenantFromUserPreference: Invalid tenant ID format', [
                    'user_id' => $user->id,
                    'tenant_id' => $tenantId,
                ]);
                return response()->json([
                    'message' => 'Invalid tenant ID format.',
                ], 400);
            }

            $tenant = \App\Models\Tenant::find($tenantId);
            if (!$tenant) {
                return response()->json([
                    'message' => 'Specified tenant not found.',
                ], 404);
            }

            Log::info('SetTenantFromUserPreference: Super-admin selected tenant', [
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
            ]);

            $this->tenancy->initialize($tenant);
            $resolvedTenant = $tenant;
        } else {
            $requestedTenantId = $request->header('X-Tenant-ID');

            if ($requestedTenantId) {
                if (!$this->isValidUuid($requestedTenantId)) {
                    Log::warning('SetTenantFromUserPreference: Invalid tenant ID format in header, falling back to default', [
                        'user_id' => $user->id,
                        'tenant_id' => $requestedTenantId,
                    ]);
                    $requestedTenantId = null;
                }
            }

            if ($requestedTenantId) {
                try {
                    $requestedAccess = DB::table('public.user_company_access')
                        ->where('user_id', $user->id)
                        ->where('tenant_id', $requestedTenantId)
                        ->first();
                } catch (\Exception $e) {
                    Log::error('SetTenantFromUserPreference: Error querying user_company_access', [
                        'user_id' => $user->id,
                        'tenant_id' => $requestedTenantId,
                        'error' => $e->getMessage(),
                    ]);
                    $requestedAccess = null;
                }

                if (!$requestedAccess) {
                    Log::warning('SetTenantFromUserPreference: User requested unauthorized tenant', [
                        'user_id' => $user->id,
                        'tenant_id' => $requestedTenantId,
                        'action' => 'fallback_to_default_tenant',
                    ]);
                    $requestedTenantId = null;
                }

                if ($requestedTenantId) {
                    try {
                        $tenant = \App\Models\Tenant::find($requestedTenantId);
                    } catch (\Exception $e) {
                        Log::error('SetTenantFromUserPreference: Error finding tenant', [
                            'user_id' => $user->id,
                            'tenant_id' => $requestedTenantId,
                            'error' => $e->getMessage(),
                        ]);
                        $tenant = null;
                    }

                    if ($tenant) {
                        Log::info('SetTenantFromUserPreference: Using requested tenant for user', [
                            'user_id' => $user->id,
                            'tenant_id' => $tenant->id,
                        ]);

                        $this->tenancy->initialize($tenant);
                        $resolvedTenant = $tenant;
                    } else {
                        Log::warning('SetTenantFromUserPreference: Requested tenant not found, falling back to default', [
                            'user_id' => $user->id,
                            'tenant_id' => $requestedTenantId,
                        ]);
                    }
                }
            }

            if (!$resolvedTenant) {
                // Get user's default company from public schema
                try {
                    $defaultCompany = DB::table('public.user_company_access')
                        ->where('user_id', $user->id)
                        ->where('is_default', true)
                        ->first();
                } catch (\Exception $e) {
                    Log::error('SetTenantFromUserPreference: Error querying default company', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                    $defaultCompany = null;
                }

                if (!$defaultCompany) {
                    Log::warning('SetTenantFromUserPreference: No tenant mapping for user', [
                        'user_id' => $user->id,
                    ]);
                    return response()->json([
                        'message' => 'No tenant configured for this user.',
                    ], 403);
                }

                try {
                    $tenant = \App\Models\Tenant::find($defaultCompany->tenant_id);
                } catch (\Exception $e) {
                    Log::error('SetTenantFromUserPreference: Error finding tenant for default company', [
                        'user_id' => $user->id,
                        'tenant_id' => $defaultCompany->tenant_id,
                        'error' => $e->getMessage(),
                    ]);
                    $tenant = null;
                }
                
                if (!$tenant) {
                    Log::error('SetTenantFromUserPreference: Tenant not found', [
                        'user_id' => $user->id,
                        'tenant_id' => $defaultCompany->tenant_id,
                    ]);
                    return response()->json([
                        'message' => 'Tenant not found.',
                    ], 403);
                }

                Log::info('SetTenantFromUserPreference: Initializing tenant', [
                    'user_id' => $user->id,
                    'tenant_id' => $tenant->id,
                ]);
                
                $this->tenancy->initialize($tenant);
                $resolvedTenant = $tenant;
            }
        }

        $response = $next($request);

        if ($resolvedTenant) {
            $response->headers->set('X-Tenant-ID', (string) $resolvedTenant->id);
        }

        return $response;
    }
}
