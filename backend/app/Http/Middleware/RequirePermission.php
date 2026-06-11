<?php

namespace App\Http\Middleware;

use App\Services\TenantRoleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require a specific Spatie permission for the current tenant context.
 *
 * Usage in routes:
 *   Route::post('/invoices', [...])->middleware(RequirePermission::using('sales.write'));
 *   Route::middleware([RequirePermission::class.':accounting.post'])->group(...);
 */
class RequirePermission
{
    public function __construct(
        private readonly TenantRoleService $roleService,
    ) {}

    /**
     * Specify the permission for the middleware.
     */
    public static function using(string $permission): string
    {
        return self::class . ':' . $permission;
    }

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Super-admin bypass
        if ($user->is_super_admin ?? false) {
            return $next($request);
        }

        // Global platform_super_admin bypass (legacy)
        if ($user->role === 'platform_super_admin') {
            return $next($request);
        }

        $tenantId = $this->resolveTenantId($request);

        if (! $tenantId) {
            return response()->json([
                'message' => 'Tenant context required.',
            ], 403);
        }

        // Resolve central user id — user_company_access stores central IDs, not tenant schema IDs
        $centralUserId = $this->resolveCentralUserId($user);

        if ($this->roleService->hasPermission($centralUserId, $tenantId, $permission)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'You do not have permission to perform this action.',
            'required_permission' => $permission,
        ], 403);
    }

    private function resolveTenantId(Request $request): ?string
    {
        if (function_exists('tenant') && tenant()) {
            $id = tenant('id');
            if ($id !== null && $id !== '') {
                return (string) $id;
            }
        }

        $headerTenantId = $request->header('X-Tenant-ID');
        if (is_string($headerTenantId) && $headerTenantId !== '') {
            return $headerTenantId;
        }

        return null;
    }

    private function resolveCentralUserId(\App\Models\User $user): int
    {
        $centralUserId = \Illuminate\Support\Facades\DB::table('public.users')
            ->where('email', $user->email)
            ->value('id');

        return (int) ($centralUserId ?? $user->id);
    }
}
