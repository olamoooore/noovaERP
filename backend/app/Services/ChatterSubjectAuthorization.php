<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Issue #74 (hostile re-review B1): the Communication module had no
 * record-level authorization — `EnsureCommunicationAccess` resolved the
 * record and set `chatter_record`, but nothing read it. Any authenticated
 * tenant user with the communication entitlement could read/write threads and
 * subscribers for any registry record.
 *
 * This platform-owned service holds the single authorization contract used by
 * BOTH chatter surfaces:
 *   - ChatterController::authorizeSubjectAccess (HTTP chatter API)
 *   - EnsureCommunicationAccess + CommunicationController (module API)
 *
 * Contract (explicit decision, matching the preserved Chatter contract):
 *   - the subject's mapped domain permission (config chatter.subject_permissions)
 *     gates access for reads AND writes;
 *   - unmapped subjects fail closed (403);
 *   - super-admins bypass, matching RequirePermission / TenantRoleService.
 */
class ChatterSubjectAuthorization
{
    public function __construct(
        private readonly ChatterModelRegistry $registry,
        private readonly TenantRoleService $roleService,
    ) {}

    /**
     * Normalize a subject reference (FQCN or registry alias) to a class name.
     */
    public function resolveSubjectClass(string $subject): ?string
    {
        if (class_exists($subject)) {
            return $subject;
        }

        return $this->registry->resolveClass($subject);
    }

    /**
     * The domain permission that gates this subject type, or null when the
     * subject is unmapped (callers must fail closed).
     */
    public function permissionFor(string $subject): ?string
    {
        $class = $this->resolveSubjectClass($subject);
        if ($class === null) {
            return null;
        }

        $permissions = (array) config('chatter.subject_permissions', []);

        if (isset($permissions[$class])) {
            return $permissions[$class];
        }

        // Defensive fallback: some registry aliases still point at legacy
        // namespace paths (e.g. Modules\Accounting\Models\Invoice vs
        // App\Models\Invoice — tracked in #157). Match the configured class
        // with the same basename so a correctly-mapped subject is not
        // fail-closed merely because the alias is stale.
        $basename = class_basename($class);
        foreach ($permissions as $configuredClass => $permission) {
            if (class_basename((string) $configuredClass) === $basename) {
                return $permission;
            }
        }

        return null;
    }

    public function allows(Request $request, string $subject): bool
    {
        $permission = $this->permissionFor($subject);
        if ($permission === null) {
            return false;
        }

        $user = $request->user();
        if (! $user) {
            return false;
        }

        if (($user->is_super_admin ?? false) || ($user->role ?? null) === 'platform_super_admin') {
            return true;
        }

        $tenantId = (function_exists('tenant') && tenant())
            ? (string) tenant('id')
            : (string) $request->header('X-Tenant-ID', '');

        if ($tenantId === '') {
            return false;
        }

        $centralUserId = (int) (DB::table('public.users')->where('email', $user->email)->value('id') ?? $user->id);

        return $this->roleService->hasPermission($centralUserId, $tenantId, $permission);
    }
}
