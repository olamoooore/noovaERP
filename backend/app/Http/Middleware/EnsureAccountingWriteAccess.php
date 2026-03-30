<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates accounting write operations (POST, PUT, PATCH, DELETE) behind permission checks.
 * Read operations (GET, HEAD, OPTIONS) pass through for all authenticated users.
 */
class EnsureAccountingWriteAccess
{
    /**
     * Roles that implicitly have accounting write access.
     */
    private const WRITE_ROLES = ['admin', 'super-admin', 'Owner', 'accountant'];

    /**
     * Permission that grants accounting write access.
     */
    private const WRITE_PERMISSION = 'accounting.write';

    /**
     * Central tenant access roles that can write accounting data.
     */
    private const WRITE_TENANT_ACCESS_ROLES = ['owner', 'admin', 'accountant'];

    public function handle(Request $request, Closure $next): Response
    {
        // Read operations are allowed for all authenticated users
        if ($request->isMethod('GET') || $request->isMethod('HEAD') || $request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->is_super_admin ?? false) {
            return $next($request);
        }

        // Check role-based access
        if (method_exists($user, 'hasRole')) {
            foreach (self::WRITE_ROLES as $role) {
                if ($user->hasRole($role)) {
                    return $next($request);
                }
            }
        }

        if ($this->hasCentralTenantWriteAccess($user->id)) {
            return $next($request);
        }

        // Check permission-based access
        if (method_exists($user, 'hasPermissionTo')) {
            try {
                if ($user->hasPermissionTo(self::WRITE_PERMISSION)) {
                    return $next($request);
                }
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
                // Permission not yet seeded — fall through to role-only check
                // In production, ensure this permission is seeded
            }
        }

        return response()->json([
            'message' => 'You do not have permission to perform accounting write operations.',
        ], 403);
    }

    private function hasCentralTenantWriteAccess(int $userId): bool
    {
        $tenantId = tenant('id');

        if (!$tenantId) {
            return false;
        }

        $accessTable = DB::connection()->getDriverName() === 'pgsql'
            ? 'public.user_company_access'
            : 'user_company_access';

        return DB::table($accessTable)
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->whereIn('role', self::WRITE_TENANT_ACCESS_ROLES)
            ->exists();
    }
}
