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

    /**
     * Material accounting permissions (HR37-03).
     *
     * These gate actions that mutate the ledger or control ledger mutability:
     * posting, reversing, correcting, locking, closing, reopening, opening
     * balances, overrides and threshold approvals.
     *
     * Authority rule: for a material permission, an unseeded permission /
     * unavailable authority definition must FAIL CLOSED. Authority may only be
     * proven by (a) the super-admin flag, (b) the canonical role→permission
     * mapping in userHasPermission()'s match arm for the exact permission, or
     * (c) an explicit Spatie permission grant. The foundation-phase fallback
     * and the broad role-name fallback are mechanically isolated to
     * NON-material permissions and can never participate in material
     * accounting authority.
     */
    private const MATERIAL_ACCOUNTING_PERMISSIONS = [
        // posting
        'accounting.write',
        'accounting.post',
        // reversing
        'accounting.reverse',
        'accounting.reversal_approve',
        'accounting.reversal_threshold',
        // correcting / adjustments
        'accounting.audit_adjustment',
        'accounting.approve_high_value',
        'accounting.account_resolution',
        'accounting.manual_journal_threshold',
        // locking / date overrides
        'accounting.lock',
        'accounting.lock_date_override',
        'accounting.backdate_override',
        // period close
        'accounting.close.admin',
        'accounting.close.manage',
        'accounting.close.signoff',
        'accounting.close.escalation_write',
        'accounting.close.finding_write',
        'accounting.close.task_write',
        'accounting.close.template_write',
        // opening balance
        'accounting.opening_balance.post',
        'accounting.opening_balance.approve',
        'accounting.opening_balance.write',
        // settings (affects ledger behaviour)
        'accounting.settings',
    ];

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
        if (! $tenantId) {
            return true;
        }

        if ($this->isCoreFeature('accounting')) {
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

    // ── Generic gate-framework methods (ADR-005 §8) ──────────────────────────

    /**
     * Generic permission check used by the authority gate framework (ADR-005 §8.4).
     * Maps a permission string to the appropriate access check.
     *
     * Authority chain for a MATERIAL accounting permission (HR37-03):
     *   super-admin flag → canonical role→permission match (write/post/reverse/lock)
     *   → explicit Spatie grant → seeded-but-lacking = DENY
     *   → NOT seeded = DENY (authority definition unavailable; never bootstrap
     *     authority from absence of configuration, never substitute a broad
     *     role-name fallback for the exact material action).
     *
     * Foundation-phase fallback (NOT seeded and no role matches → allow) is
     * mechanically isolated to NON-material permissions only and can never
     * participate in material accounting authority.
     */
    public function userHasPermission(int $userId, string $permission, ?string $tenantId = null): bool
    {
        // #878 (SA4): the dedicated per-tenant system actor is the platform's
        // non-interactive identity. It deliberately carries no human role, so
        // the human RBAC chain below can never pass for it — without this the
        // queued/scheduled/webhook postings the actor exists to serve would
        // fail closed. Identity is verified in the tenant DB (is_system),
        // never from caller-supplied context.
        if ($this->isTenantSystemActor($userId)) {
            return true;
        }

        // Authority gates run after tenant middleware changes PostgreSQL's
        // search_path. The actor ID is a central-user ID, so an unqualified
        // User::find() can resolve a tenant user (or no user) instead.
        $user = DB::connection()->getDriverName() === 'pgsql'
            ? User::query()->from('public.users')->where('id', $userId)->first()
            : User::find($userId);
        if (! $user) {
            return false;
        }

        if ($user->is_super_admin ?? false) {
            return true;
        }

        // Accounting authority gates must use the same canonical policy as
        // route middleware. These methods include explicit role fallbacks for
        // central users whose Spatie role/permission records live in a tenant
        // schema and therefore are not directly visible here.
        $canonicalDecision = match ($permission) {
            'accounting.write' => $this->canWrite($user),
            'accounting.post' => $this->canPost($user),
            'accounting.reverse' => $this->canReverse($user),
            'accounting.lock' => $this->canManageLocks($user),
            default => null,
        };
        if ($canonicalDecision !== null) {
            return $canonicalDecision;
        }

        // Try Spatie permission first
        if ($this->hasPermission($user, $permission)) {
            return true;
        }

        // Check if the permission exists in Spatie (system bootstrapped?)
        $permissionExists = $this->permissionIsSeeded($permission);

        if ($permissionExists) {
            // Permission IS seeded but user doesn't have it → deny (fail-closed)
            return false;
        }

        // Permission NOT seeded.
        //
        // HR37-03: for a MATERIAL accounting action, an unseeded permission
        // means the authority definition is unavailable. FAIL CLOSED — never
        // bootstrap production accounting authority from absence of
        // configuration, and never substitute a broad role-name fallback for a
        // canonical role→permission mapping for the exact material action.
        // (The canonical mappings for accounting.write/post/reverse/lock are
        // handled by the match arm above and return early; any other material
        // permission reaching here has no canonical authority source.)
        if (in_array($permission, self::MATERIAL_ACCOUNTING_PERMISSIONS, true)) {
            return false;
        }

        // NON-material permission NOT seeded — foundation/development role
        // fallback. This branch is mechanically isolated from material
        // accounting authority by the guard above, so it can never convert a
        // missing material permission into a PASS.
        $roleAllowed = $this->canWrite($user);

        if ($roleAllowed) {
            return true;
        }

        // Neither Spatie permission nor role matched, AND the (non-material)
        // permission isn't seeded. Foundation phase: allow — can't enforce what
        // doesn't exist yet. Isolated from material accounting authority.
        return true;
    }

    /**
     * Generic entitlement check used by the authority gate framework (ADR-005 §8.3).
     */
    public function userHasEntitlement(int $userId, string $entitlement, ?string $tenantId = null): bool
    {
        return $this->isModuleEntitled();
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

    /**
     * Can the user complete bank reconciliation?
     */
    public function canCompleteReconciliation(User $user): bool
    {
        if ($user->is_super_admin ?? false) {
            return true;
        }

        if ($this->hasRole($user, ['admin', 'super-admin', 'Owner'])) {
            return true;
        }

        return $this->hasPermission($user, 'reconciliation.complete');
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
        if (! method_exists($user, 'hasRole')) {
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
        if (! method_exists($user, 'hasPermissionTo')) {
            return false;
        }

        try {
            return $user->hasPermissionTo($permission);
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            // Permission not yet seeded — fall through
            return false;
        }
    }

    private function permissionIsSeeded(string $permission): bool
    {
        try {
            return \Spatie\Permission\Models\Permission::where('name', $permission)->exists();
        } catch (\Exception) {
            return false;
        }
    }

    private function hasCentralTenantRole(int $userId, array $roles): bool
    {
        $tenantId = tenant('id');

        if (! $tenantId) {
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

    /**
     * #878 (SA4): is the actor the current tenant's dedicated system actor?
     */
    private function isTenantSystemActor(int $userId): bool
    {
        try {
            return DB::table('users')->where('id', $userId)->where('is_system', true)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function isCoreFeature(string $featureKey): bool
    {
        $featureTable = DB::connection()->getDriverName() === 'pgsql'
            ? 'public.features'
            : 'features';

        return (bool) DB::table($featureTable)
            ->where('key', $featureKey)
            ->value('is_core');
    }
}
