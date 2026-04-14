<?php

namespace App\Services;

use App\Models\User;
use App\SuperAdmin\Application\EntitlementService;
use Illuminate\Support\Facades\DB;

/**
 * AccountingAccessService
 *
 * Single source of truth for all accounting access decisions.
 * Replaces the three-path check that was duplicated across middleware and
 * controllers (hardcoded roles + user_company_access DB query + Spatie permission).
 *
 * Access hierarchy:
 *   1. Super-admin flag → always allowed
 *   2. Tenant entitlement check (accounting module must be entitled)
 *   3. Spatie permission OR role-based fallback
 *   4. Central user_company_access table fallback
 */
class AccountingAccessService
{
    /** Roles that implicitly carry accounting write access. */
    private const WRITE_ROLES = ['admin', 'super-admin', 'Owner', 'accountant'];

    /** Roles in the central user_company_access table that can write. */
    private const TENANT_WRITE_ROLES = ['owner', 'admin', 'accountant'];

    public function __construct(
        private readonly EntitlementService $entitlements,
    ) {}

    // ── Entitlement ───────────────────────────────────────────────────────────

    /**
     * Is the accounting module entitled for the current tenant?
     * Falls back to true when no tenant context is available (e.g. tests / CLI).
     */
    public function isModuleEntitled(): bool
    {
        $tenantId = tenant('id');
        if (!$tenantId) {
            return true;
        }

        return $this->entitlements->isEntitled((string) $tenantId, 'accounting');
    }

    // ── General write ─────────────────────────────────────────────────────────

    /**
     * Can the user perform any accounting write operation?
     */
    public function canWrite(User $user): bool
    {
        if ($user->is_super_admin ?? false) {
            return true;
        }

        return $this->hasSpatiePermissionOrRole($user, 'accounting.write', self::WRITE_ROLES)
            || $this->hasCentralTenantRole($user->id, self::TENANT_WRITE_ROLES);
    }

    // ── Fine-grained operations ───────────────────────────────────────────────

    /**
     * Can the user post (release a draft to the GL)?
     * Requires accounting.write AND accounting.post permission, or admin/super-admin.
     */
    public function canPost(User $user): bool
    {
        if ($user->is_super_admin ?? false) {
            return true;
        }

        if ($this->hasRole($user, ['admin', 'super-admin', 'Owner'])) {
            return true;
        }

        return $this->hasPermission($user, 'accounting.post');
    }

    /**
     * Can the user reverse a posted entry?
     * Requires accounting.write AND accounting.reverse permission, or admin/super-admin.
     */
    public function canReverse(User $user): bool
    {
        if ($user->is_super_admin ?? false) {
            return true;
        }

        if ($this->hasRole($user, ['admin', 'super-admin', 'Owner'])) {
            return true;
        }

        return $this->hasPermission($user, 'accounting.reverse');
    }

    /**
     * Can the user manage lock dates?
     */
    public function canManageLocks(User $user): bool
    {
        if ($user->is_super_admin ?? false) {
            return true;
        }

        if ($this->hasRole($user, ['admin', 'super-admin', 'Owner'])) {
            return true;
        }

        return $this->hasPermission($user, 'accounting.lock');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function hasSpatiePermissionOrRole(User $user, string $permission, array $roles): bool
    {
        if ($this->hasRole($user, $roles)) {
            return true;
        }

        return $this->hasPermission($user, $permission);
    }

    private function hasRole(User $user, array $roles): bool
    {
        if (!method_exists($user, 'hasRole')) {
            return false;
        }

        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    private function hasPermission(User $user, string $permission): bool
    {
        if (!method_exists($user, 'hasPermissionTo')) {
            return false;
        }

        try {
            return $user->hasPermissionTo($permission);
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            // Permission not yet seeded — fall through
            return false;
        }
    }

    private function hasCentralTenantRole(int $userId, array $roles): bool
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
            ->whereIn('role', $roles)
            ->exists();
    }
}
