<?php

namespace App\Services;

use App\Enums\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Resolve a user's tenant-scoped role and permissions.
 *
 * This service bridges the central public.user_company_access table
 * with the tenant-schema Spatie permission system.
 */
class TenantRoleService
{
    /**
     * Get the user's role for a given tenant.
     */
    public function getRole(int $userId, string $tenantId): ?UserRole
    {
        $rawRole = DB::table('public.user_company_access')
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->value('role');

        if (! $rawRole) {
            return null;
        }

        return UserRole::tryFrom((string) $rawRole);
    }

    /**
     * Get the Spatie role name for a user in a tenant.
     */
    public function getSpatieRoleName(int $userId, string $tenantId): ?string
    {
        $role = $this->getRole($userId, $tenantId);

        return $role?->spatieName();
    }

    /**
     * Get the permission names assigned to the user's role.
     *
     * @return string[]
     */
    public function getPermissions(int $userId, string $tenantId): array
    {
        $role = $this->getRole($userId, $tenantId);

        if (! $role) {
            return [];
        }

        // If user is Owner or Admin, return all permissions as a convenience
        if (in_array($role->value, UserRole::superRoles(), true)) {
            return Permission::where('guard_name', 'web')->pluck('name')->all();
        }

        $spatieRole = Role::findByName($role->spatieName(), 'web');

        if (! $spatieRole) {
            return [];
        }

        return $spatieRole->permissions->pluck('name')->all();
    }

    /**
     * Check if the user has a specific permission in the tenant context.
     */
    public function hasPermission(int $userId, string $tenantId, string $permission): bool
    {
        $role = $this->getRole($userId, $tenantId);

        if (! $role) {
            return false;
        }

        if (in_array($role->value, UserRole::superRoles(), true)) {
            return true;
        }

        $spatieRole = Role::findByName($role->spatieName(), 'web');

        if (! $spatieRole) {
            return false;
        }

        // A permission that was never seeded in this tenant's schema must deny
        // access — not blow up with a 500 (Spatie throws
        // PermissionDoesNotExist). Denying is the safe default and keeps the
        // middleware's 403 contract intact.
        try {
            return $spatieRole->hasPermissionTo($permission);
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
            Log::warning('TenantRoleService: permission not seeded in tenant schema', [
                'tenant_id' => $tenantId,
                'role' => $role->value,
                'permission' => $permission,
            ]);

            return false;
        }
    }

    /**
     * Check if the user has any of the given permissions.
     */
    public function hasAnyPermission(int $userId, string $tenantId, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($userId, $tenantId, $permission)) {
                return true;
            }
        }

        return false;
    }
}
