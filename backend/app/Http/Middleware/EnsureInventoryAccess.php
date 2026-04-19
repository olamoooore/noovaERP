<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureInventoryAccess
 *
 * Single-path gate for all inventory write operations.
 * Read operations (GET/HEAD/OPTIONS) pass through for all authenticated users.
 *
 * Access hierarchy:
 *   1. Tenant must have 'inventory' module entitled
 *   2. User must have an inventory write role or explicit permission
 *
 * Mirrors the pattern in EnsureAccountingWriteAccess so the two gates
 * can be evolved consistently.
 */
class EnsureInventoryAccess
{
    /** Spatie permission name for general inventory write access. */
    private const WRITE_PERMISSION = 'inventory.write';

    /** Roles that implicitly carry inventory write access. */
    private const WRITE_ROLES = ['admin', 'super-admin', 'Owner', 'inventory_manager', 'warehouse_officer', 'storekeeper', 'procurement_officer'];

    /** Central user_company_access roles. */
    private const TENANT_WRITE_ROLES = ['owner', 'admin', 'inventory_manager'];

    public function __construct(
        private readonly FeatureGateService $featureGate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Read operations pass through for any authenticated user
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])) {
            return $next($request);
        }

        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // ── 1. Tenant entitlement check (canonical: FeatureGateService + cache) ──
        $tenantId = tenant('id');
        if ($tenantId) {
            $check = $this->featureGate->check((string) $tenantId, 'inventory');
            if (!$check['is_entitled']) {
                return response()->json([
                    'message' => 'The inventory module is not enabled for this account.',
                ], 403);
            }
        }

        // ── 2. Super-admin bypass ────────────────────────────────────────────
        if ($user->is_super_admin ?? false) {
            return $next($request);
        }

        // ── 3. Spatie role or permission ─────────────────────────────────────
        if (method_exists($user, 'hasRole')) {
            foreach (self::WRITE_ROLES as $role) {
                if ($user->hasRole($role)) {
                    return $next($request);
                }
            }
        }

        if (method_exists($user, 'hasPermissionTo')) {
            try {
                if ($user->hasPermissionTo(self::WRITE_PERMISSION)) {
                    return $next($request);
                }
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
                // Permission not yet seeded — fall through
            }
        }

        // ── 4. Central tenant access table fallback ──────────────────────────
        if ($tenantId) {
            $accessTable = DB::connection()->getDriverName() === 'pgsql'
                ? 'public.user_company_access'
                : 'user_company_access';

            $hasTenantAccess = DB::table($accessTable)
                ->where('user_id', $user->id)
                ->where('tenant_id', $tenantId)
                ->whereIn('role', self::TENANT_WRITE_ROLES)
                ->exists();

            if ($hasTenantAccess) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'You do not have permission to perform inventory write operations.',
        ], 403);
    }
}
