<?php

declare(strict_types=1);

namespace App\Tenancy;

use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Events\TenancyInitialized;

/**
 * Scope Spatie's permission cache to the active tenant.
 *
 * Spatie caches the whole permission catalogue under one global key, but each
 * tenant schema has its own permission rows/ids. A shared cache makes
 * Role::hasPermissionTo() resolve a permission name to another tenant's id
 * (or a stale id after a re-seed) and deny a permission the role actually
 * holds — e.g. a 403 on customers.view for the Sales role.
 */
final class TenantPermissionCacheScope
{
    public function handle(TenancyInitialized $event): void
    {
        $tenant = $event->tenancy->tenant ?? null;

        if (! $tenant) {
            return;
        }

        $registrar = app(PermissionRegistrar::class);
        $registrar->cacheKey = config('permission.cache.key').'.'.$tenant->getTenantKey();
        $registrar->clearPermissionsCollection();
        $registrar->forgetWildcardPermissionIndex();
    }
}
