<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\TenantRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Manage users within the current tenant context.
 */
class TenantUserController extends Controller
{
    public function __construct(
        private readonly TenantRoleService $roleService,
    ) {}

    /**
     * GET /api/v1/tenant-users
     *
     * List all users assigned to the current tenant with their roles.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant context required.'], 403);
        }

        $users = DB::table('public.user_company_access')
            ->join('public.users', 'users.id', '=', 'user_company_access.user_id')
            ->where('user_company_access.tenant_id', $tenantId)
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'user_company_access.role',
                'user_company_access.is_default',
                'user_company_access.last_accessed_at',
            ])
            ->orderBy('users.name')
            ->get()
            ->map(function ($row) use ($tenantId) {
                $role = $this->roleService->getSpatieRoleName($row->id, $tenantId);

                return [
                    'id' => $row->id,
                    'name' => $row->name,
                    'email' => $row->email,
                    'role' => $row->role,
                    'role_display' => $role,
                    'is_default' => (bool) $row->is_default,
                    'last_accessed_at' => $row->last_accessed_at,
                ];
            });

        return response()->json([
            'data' => $users,
        ]);
    }

    /**
     * PUT /api/v1/tenant-users/{id}/role
     *
     * Update a user's role within the current tenant.
     * Only Owner and Admin can change roles.
     */
    public function updateRole(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant context required.'], 403);
        }

        $currentUser = $request->user();

        if (! $currentUser) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Only Owner and Admin can change roles
        $currentRole = $this->roleService->getRole($currentUser->id, $tenantId);
        if (! $currentRole || ! in_array($currentRole->value, UserRole::superRoles(), true)) {
            return response()->json([
                'message' => 'You do not have permission to change user roles.',
            ], 403);
        }

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(UserRole::values())],
        ]);

        $affected = DB::table('public.user_company_access')
            ->where('user_id', $id)
            ->where('tenant_id', $tenantId)
            ->update([
                'role' => $validated['role'],
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            return response()->json([
                'message' => 'User not found in this tenant.',
            ], 404);
        }

        // Re-sync the tenant user's Spatie role on next request
        return response()->json([
            'message' => 'Role updated successfully.',
            'data' => [
                'user_id' => $id,
                'role' => $validated['role'],
            ],
        ]);
    }

    /**
     * DELETE /api/v1/tenant-users/{id}
     *
     * Remove a user from the current tenant.
     * Only Owner can remove users.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant context required.'], 403);
        }

        $currentUser = $request->user();

        if (! $currentUser) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Only Owner can remove users
        $currentRole = $this->roleService->getRole($currentUser->id, $tenantId);
        if ($currentRole !== UserRole::OWNER) {
            return response()->json([
                'message' => 'Only the tenant owner can remove users.',
            ], 403);
        }

        // Prevent self-removal
        if ($currentUser->id === $id) {
            return response()->json([
                'message' => 'You cannot remove yourself from the tenant.',
            ], 422);
        }

        $deleted = DB::table('public.user_company_access')
            ->where('user_id', $id)
            ->where('tenant_id', $tenantId)
            ->delete();

        if ($deleted === 0) {
            return response()->json([
                'message' => 'User not found in this tenant.',
            ], 404);
        }

        return response()->json([
            'message' => 'User removed from tenant successfully.',
        ]);
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
}
