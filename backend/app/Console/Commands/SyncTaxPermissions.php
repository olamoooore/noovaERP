<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tenancy;

/**
 * MRD R7.5 switchover: backfill tax.view / tax.manage in every tenant schema
 * by mirroring who currently holds reports.view / reports.manage — so the
 * permission split changes nobody's effective access at rollout time.
 * Idempotent.
 */
class SyncTaxPermissions extends Command
{
    protected $signature = 'taxes:sync-permissions';

    protected $description = 'Grant tax.view/tax.manage mirroring reports.view/reports.manage in every tenant schema';

    public function handle(Tenancy $tenancy): int
    {
        $tenants = Tenant::orderBy('id')->get();

        if ($tenants->isEmpty()) {
            $this->info('No tenants found.');

            return self::SUCCESS;
        }

        $originalTenant = $tenancy->tenant;
        $originalPath = (string) (DB::selectOne('SHOW search_path')->search_path ?? 'public');

        foreach ($tenants as $tenant) {
            $tenancy->initialize($tenant);

            try {
                $granted = $this->syncCurrentSchema();
                $this->info("{$tenant->getTenantKey()}: {$granted} role grant(s) synchronized.");
            } finally {
                $tenancy->end();
            }
        }

        DB::statement('SET search_path TO '.DB::connection()->getPdo()->quote($originalPath));

        if ($originalTenant) {
            $tenancy->initialize($originalTenant);
        }

        return self::SUCCESS;
    }

    /**
     * Backfill tax.view/tax.manage in the CURRENT tenant schema, mirroring
     * current reports.view/reports.manage holders. Public for direct reuse
     * (provisioning pipeline + tests).
     */
    public function syncCurrentSchema(): int
    {
        $ids = $this->ensurePermissions();

        // Legacy switchover (R7.5): tax.view/tax.manage mirror reports.*.
        $mirrors = [
            [$ids['tax.view'], 'reports.view'],
            [$ids['tax.manage'], 'reports.manage'],
        ];

        // #273 provisioning tail: the policy/determine families now ship with
        // the tenant seeder; existing tenants get them from their natural
        // counterparts — policy.view from tax.view, determine from tax.manage,
        // and policy.manage for ELEVATED roles only (separation of duties).
        $mirrors[] = [$ids['tax.policy.view'], 'tax.view'];
        $mirrors[] = [$ids['tax.determine'], 'tax.manage'];

        $granted = 0;

        foreach ($mirrors as [$taxPermissionId, $sourcePermission]) {
            $roleIds = DB::table('role_has_permissions as rhp')
                ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
                ->where('p.name', $sourcePermission)
                ->pluck('rhp.role_id')
                ->unique();

            $already = DB::table('role_has_permissions')->where('permission_id', $taxPermissionId)->pluck('role_id')->all();

            foreach ($roleIds as $roleId) {
                if (in_array((int) $roleId, array_map('intval', $already), true)) {
                    continue;
                }

                DB::table('role_has_permissions')->insert([
                    'permission_id' => $taxPermissionId,
                    'role_id' => (int) $roleId,
                ]);
                $granted++;
            }
        }

        $elevatedRoleIds = DB::table('roles')
            ->whereIn('name', ['admin', 'Admin', 'Owner', 'owner', 'super-admin', 'super_admin'])
            ->pluck('id')
            ->all();

        $alreadyPolicy = array_map('intval', DB::table('role_has_permissions')->where('permission_id', $ids['tax.policy.manage'])->pluck('role_id')->all());

        foreach ($elevatedRoleIds as $roleId) {
            if (in_array((int) $roleId, $alreadyPolicy, true)) {
                continue;
            }

            DB::table('role_has_permissions')->insert([
                'permission_id' => $ids['tax.policy.manage'],
                'role_id' => (int) $roleId,
            ]);
            $granted++;
        }

        return $granted;
    }

    /**
     * @return array<string, int> permission ids keyed by name
     */
    private function ensurePermissions(): array
    {
        $ids = [];

        foreach (['tax.view', 'tax.manage', 'tax.policy.view', 'tax.policy.manage', 'tax.determine'] as $name) {
            $existing = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->first();

            if ($existing) {
                $ids[$name] = (int) $existing->id;

                continue;
            }

            $ids[$name] = (int) DB::table('permissions')->insertGetId([
                'name' => $name,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }
}
