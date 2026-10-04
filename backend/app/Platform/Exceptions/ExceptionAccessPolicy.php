<?php

namespace App\Platform\Exceptions;

use App\Enums\UserRole;
use App\Http\Middleware\RequirePermission;
use Illuminate\Http\Request;

/**
 * Issue #914: per-exception-type authorization for the operations console.
 *
 * The queue stores the role that owns each exception (`owner_role`, set by
 * #902's ExceptionReport from the typed ExceptionType). The console maps that
 * role onto EXISTING permissions from RolePermissionSeeder's matrix — no new
 * permission strings are introduced:
 *
 *   owner_role=accountant → view accounting.view / act accounting.write
 *   owner_role=sales      → view sales.view      / act sales.write
 *   owner_role=null/other → view settings.view   / act settings.write (Owner/Admin)
 *
 * An exception is triaged by the same audience that can perform the owning
 * role's work; the fallback covers legacy rows written by
 * ExceptionService::log() (e.g. the #912 audit-chain alert), which only
 * Owner/Admin may handle.
 */
class ExceptionAccessPolicy
{
    /** @var array<string, array{read: string, write: string}> */
    private const ROLE_PERMISSIONS = [
        UserRole::ACCOUNTANT->value => ['read' => 'accounting.view', 'write' => 'accounting.write'],
        UserRole::SALES->value => ['read' => 'sales.view', 'write' => 'sales.write'],
    ];

    public const DEFAULT_READ_PERMISSION = 'settings.view';

    public const DEFAULT_WRITE_PERMISSION = 'settings.write';

    public function readPermission(?string $ownerRole): string
    {
        return self::ROLE_PERMISSIONS[$ownerRole]['read'] ?? self::DEFAULT_READ_PERMISSION;
    }

    public function writePermission(?string $ownerRole): string
    {
        return self::ROLE_PERMISSIONS[$ownerRole]['write'] ?? self::DEFAULT_WRITE_PERMISSION;
    }

    public function canRead(Request $request, ?string $ownerRole): bool
    {
        return RequirePermission::allows($request, $this->readPermission($ownerRole));
    }

    public function canWrite(Request $request, ?string $ownerRole): bool
    {
        return RequirePermission::allows($request, $this->writePermission($ownerRole));
    }

    /**
     * The owner_role values whose READ permission the caller holds — used to
     * scope the list/stats query in SQL.
     *
     * @return list<string>
     */
    public function readableOwnerRoles(Request $request): array
    {
        $roles = [];

        foreach (self::ROLE_PERMISSIONS as $ownerRole => $permissions) {
            if (RequirePermission::allows($request, $permissions['read'])) {
                $roles[] = $ownerRole;
            }
        }

        return $roles;
    }

    /**
     * Whether the caller may read exceptions with no owner_role (legacy
     * platform rows, e.g. #912's audit-chain alert).
     */
    public function canReadUnowned(Request $request): bool
    {
        return RequirePermission::allows($request, self::DEFAULT_READ_PERMISSION);
    }
}
