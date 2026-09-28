<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequirePermission;
use App\Services\RoleCustomizationService;
use App\Services\TenantRoleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tenant-facing RBAC: list roles with their permission sets and edit the
 * permissions granted to a role. Owner and Admin are intentionally locked —
 * UserRole::superRoles() bypass every permission check, so editing their
 * Spatie rows would look effective while changing nothing.
 */
class TenantRoleController extends Controller
{
    private const MODULE_LABELS = [
        'dashboard' => 'Dashboard',
        'pos' => 'Point of Sale',
        'inventory' => 'Inventory',
        'medical_cases' => 'Medical Cases',
        'bonus_schemes' => 'Bonus Schemes',
        'products' => 'Products',
        'sales' => 'Sales',
        'invoices' => 'Invoices',
        'fulfillment' => 'Fulfillment',
        'customers' => 'Customers',
        'contacts' => 'Contacts',
        'accounting' => 'Accounting',
        'reconciliation' => 'Reconciliation',
        'reports' => 'Reports',
        'settings' => 'Settings',
        'users' => 'Team & Access',
        'entities' => 'Legal Entities',
        'document' => 'Documents',
        'documents' => 'Documents',
        'form' => 'Forms',
        'survey' => 'Surveys',
        'template' => 'Templates',
        'loyalty' => 'Loyalty',
        'hrm' => 'HRM',
        'storefront' => 'Storefront',
    ];

    public function __construct(
        private readonly TenantRoleService $roleService,
    ) {}

    /**
     * GET /api/v1/tenant-roles — roles with permission sets + catalog.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->authorizeManage($request);

        if ($tenantId instanceof JsonResponse) {
            return $tenantId;
        }

        $permissionNames = Permission::where('guard_name', 'web')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $roles = [];
        foreach (UserRole::cases() as $userRole) {
            $isSuper = in_array($userRole->value, UserRole::superRoles(), true);

            $permissions = $permissionNames;
            if (! $isSuper) {
                $spatieRole = Role::where('name', $userRole->spatieName())->where('guard_name', 'web')->first();
                $permissions = $spatieRole
                    ? $spatieRole->permissions->pluck('name')->intersect($permissionNames)->values()->all()
                    : [];
            }

            $roles[] = [
                'value' => $userRole->value,
                'label' => $userRole->label(),
                'is_super' => $isSuper,
                'permissions' => $permissions,
                'member_count' => DB::table('public.user_company_access')
                    ->where('tenant_id', $tenantId)
                    ->where('role', $userRole->value)
                    ->count(),
            ];
        }

        return response()->json([
            'roles' => $roles,
            'catalog' => $this->catalog($permissionNames),
        ]);
    }

    /**
     * PUT /api/v1/tenant-roles/{role} — replace the role's permission set.
     */
    public function updatePermissions(Request $request, string $role): JsonResponse
    {
        $tenantId = $this->authorizeManage($request);

        if ($tenantId instanceof JsonResponse) {
            return $tenantId;
        }

        $userRole = UserRole::tryFrom($role);

        if (! $userRole) {
            return response()->json(['message' => 'Unknown role.'], 404);
        }

        if (in_array($userRole->value, UserRole::superRoles(), true)) {
            return response()->json([
                'message' => 'Owner and Admin always have full access and cannot be limited.',
            ], 422);
        }

        $validated = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $spatieRole = Role::firstOrCreate([
            'name' => $userRole->spatieName(),
            'guard_name' => 'web',
        ]);

        $spatieRole->syncPermissions(array_values(array_unique($validated['permissions'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Track tenant customizations so RolePermissionSeeder (tenants:seed
        // on a deploy) does not revert them; a set that matches the matrix
        // default is not a customization and keeps converging with the seeder.
        $current = $spatieRole->permissions()->pluck('permissions.name')->sort()->values()->all();
        $defaults = RolePermissionSeeder::defaultPermissionsFor($userRole);
        sort($defaults);

        $customizations = app(RoleCustomizationService::class);
        if ($current === $defaults) {
            $customizations->clearCustomized($userRole->value);
        } else {
            $customizations->markCustomized($userRole->value);
        }

        return response()->json([
            'message' => $userRole->label().' permissions updated.',
            'data' => [
                'value' => $userRole->value,
                'label' => $userRole->label(),
                'permissions' => $current,
            ],
        ]);
    }

    /**
     * Shared gate: tenant context + Owner/Admin caller. Returns the tenant id
     * or a JsonResponse error.
     */
    private function authorizeManage(Request $request): string|JsonResponse
    {
        $tenantId = RequirePermission::resolveTenantId($request);

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant context required.'], 403);
        }

        $currentUser = $request->user();

        if (! $currentUser) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $currentRole = $this->roleService->getRole(RequirePermission::resolveCentralUserId($currentUser), $tenantId);

        if (! $currentRole || ! in_array($currentRole->value, UserRole::superRoles(), true)) {
            return response()->json([
                'message' => 'Only an Owner or Admin can manage roles and permissions.',
            ], 403);
        }

        return $tenantId;
    }

    /**
     * Group the seeded permission names by module for the matrix UI.
     */
    private function catalog(array $permissionNames): array
    {
        $groups = [];

        foreach ($permissionNames as $name) {
            $prefix = explode('.', $name)[0];
            $tail = Str::headline(str_replace('.', ' ', substr($name, strlen($prefix) + 1)));
            $tail = str_replace(['Hr', 'Pos', 'Id'], ['HR', 'POS', 'ID'], $tail);

            $groups[$prefix] ??= [
                'module' => $prefix,
                'label' => self::MODULE_LABELS[$prefix] ?? Str::headline($prefix),
                'permissions' => [],
            ];

            $groups[$prefix]['permissions'][] = [
                'name' => $name,
                'label' => $tail,
            ];
        }

        ksort($groups);

        return array_values($groups);
    }
}
