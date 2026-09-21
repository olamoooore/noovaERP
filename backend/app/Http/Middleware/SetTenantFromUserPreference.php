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

        if (! $user) {
            return $next($request);
        }

        $resolvedTenant = null;

        if ($user->is_super_admin ?? false) {
            // Super-admins must explicitly select a tenant via X-Tenant-ID header
            $tenantId = $request->header('X-Tenant-ID');
            if (! $tenantId) {
                return response()->json([
                    'message' => 'Super-admin must specify X-Tenant-ID header to select a tenant context.',
                ], 400);
            }

            if (! $this->isValidUuid($tenantId)) {
                Log::warning('SetTenantFromUserPreference: Invalid tenant ID format', [
                    'user_id' => $user->id,
                    'tenant_id' => $tenantId,
                ]);

                return response()->json([
                    'message' => 'Invalid tenant ID format.',
                ], 400);
            }

            $tenant = \App\Models\Tenant::find($tenantId);
            if (! $tenant) {
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
                if (! $this->isValidUuid($requestedTenantId)) {
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

                if (! $requestedAccess) {
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

            if (! $resolvedTenant) {
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

                if (! $defaultCompany) {
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

                if (! $tenant) {
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

        // Issue #172 (CRITICAL): enforce tenant.data.suspended. The flag is set
        // by the SuperAdmin console (TenantOperationService::suspend) but is
        // never consulted at the tenant-resolution layer, so a suspended tenant
        // has full unrestricted access. Block non-super-admin requests with 403
        // here. Super-admin bypasses — they need to manage a suspended tenant
        // via the SuperAdmin console and the X-Tenant-ID impersonation path.
        //
        // Read the flag via both the Eloquent accessor (the canonical path that
        // TenantOperationService::suspend uses to write) AND the raw json
        // column — the latter is a defence-in-depth fallback in case the model's
        // internal storage path differs (some stancl/tenancy configurations
        // store tenant.data via internal keys rather than the raw json column,
        // and the model accessor may not reflect the raw column). Either path
        // being true is enough to trigger the block.
        if ($resolvedTenant && ! ($user->is_super_admin ?? false)) {
            $suspended = false;

            // 1. Eloquent accessor (the production write path).
            $modelData = $resolvedTenant->data ?? [];
            if (is_array($modelData) && (bool) ($modelData['suspended'] ?? false)) {
                $suspended = true;
            }

            // 2. Raw json column fallback.
            if (! $suspended) {
                $raw = DB::table('public.tenants')->where('id', $resolvedTenant->id)->value('data');
                $suspended = self::isSuspendedFlagSet($raw);
            }

            if ($suspended) {
                Log::warning('SetTenantFromUserPreference: blocked suspended tenant', [
                    'user_id' => $user->id,
                    'tenant_id' => $resolvedTenant->id,
                ]);

                return response()->json([
                    'message' => 'Tenant is suspended. Contact your platform administrator.',
                ], 403);
            }
        }

        $response = $next($request);

        if ($resolvedTenant) {
            $response->headers->set('X-Tenant-ID', (string) $resolvedTenant->id);
        }

        return $response;
    }

    /**
     * Issue #172 — pure evaluator for the suspended flag as stored in
     * public.tenants.data. Accepts either the raw json string (as returned by
     * the DB driver for a json/jsonb column) or an already-decoded array, and
     * returns true only when `suspended` is truthy. Extracted so the read
     * decision is independently unit-testable without the DB/tenancy layer.
     */
    public static function isSuspendedFlagSet(mixed $raw): bool
    {
        if (is_array($raw)) {
            return (bool) ($raw['suspended'] ?? false);
        }
        if (is_string($raw) && $raw !== '' && $raw !== 'null') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) && (bool) ($decoded['suspended'] ?? false);
        }

        return false;
    }
}
