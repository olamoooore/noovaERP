<?php

namespace App\Http\Middleware;

use Closure;
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

        // Check role-based access
        if (method_exists($user, 'hasRole')) {
            foreach (self::WRITE_ROLES as $role) {
                if ($user->hasRole($role)) {
                    return $next($request);
                }
            }
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
}
