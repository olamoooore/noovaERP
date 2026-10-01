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
        return self::class.':'.$permission;
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

        $tenantId = self::resolveTenantId($request);

        if (! $tenantId) {
            return response()->json([
                'message' => 'Tenant context required.',
            ], 403);
        }

        // Resolve central user id — user_company_access stores central IDs, not tenant schema IDs
        $centralUserId = self::resolveCentralUserId($user);

        // G-11 (#274): permission families may be expressed as alternatives —
        // e.g. 'tax.policy.manage|tax.manage' passes when the role holds ANY of
        // the listed permissions. Single-permission usage is unchanged.
        $hasPerm = false;
        foreach (array_filter(array_map('trim', explode('|', $permission))) as $candidate) {
            if ($this->roleService->hasPermission($centralUserId, $tenantId, $candidate)) {
                $hasPerm = true;
                break;
            }
        }

        if ($hasPerm) {
            return $next($request);
        }

        return response()->json([
            'message' => 'You do not have permission to perform this action.',
            'required_permission' => $permission,
        ], 403);
    }

    /**
     * #278: shared controller-level re-check for endpoints whose action needs
     * a stronger authority than the route middleware (e.g. reopening a filed
     * tax period). Same resolution as the middleware.
     */
    public static function allows(Request $request, string $permission): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        if (($user->is_super_admin ?? false) || $user->role === 'platform_super_admin') {
            return true;
        }

        $tenantId = self::resolveTenantId($request);

        if (! $tenantId) {
            return false;
        }

        return app(TenantRoleService::class)->hasPermission(
            self::resolveCentralUserId($user),
            $tenantId,
            $permission,
        );
    }

    /**
     * Issue #227: shared (public) so controller defence-in-depth re-checks use
     * the EXACT same resolution as this middleware instead of a drifting copy.
     */
    public static function resolveTenantId(Request $request): ?string
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

    /**
     * Resolve the CENTRAL user id for a request user. After
     * SyncTenantUserFromCentralAuth the instance is the tenant-schema user,
     * but public.user_company_access stores central ids — every controller
     * resolving roles/tenants must use this instead of $user->id.
     */
    public static function resolveCentralUserId(\App\Models\User $user): int
    {
        $centralUserId = \Illuminate\Support\Facades\DB::table('public.users')
            ->where('email', $user->email)
            ->value('id');

        return (int) ($centralUserId ?? $user->id);
    }
}
