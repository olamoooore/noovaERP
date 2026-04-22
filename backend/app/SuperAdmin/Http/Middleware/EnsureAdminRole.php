<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRole
{
    private const ADMIN_ROLES = [
        'platform_super_admin',
        'platform_ops',
        'sales_ops',
        'billing_ops',
        'support_readonly',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, self::ADMIN_ROLES, true)) {
            return response()->json([
                'message' => 'Forbidden. Platform admin access required.',
            ], 403);
        }

        // Check if the admin account is deactivated
        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your admin account has been deactivated.',
            ], 403);
        }

        return $next($request);
    }
}
