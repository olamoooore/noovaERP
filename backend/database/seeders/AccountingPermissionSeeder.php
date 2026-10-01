<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AccountingPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'accounting.write',
            'accounting.post',
            'accounting.reverse',
            'accounting.lock',
            'reconciliation.complete',
            'reconciliation.view',
            'reconciliation.import',
            'reconciliation.match',
            'reconciliation.adjust',
            'reconciliation.exclude',
            'reconciliation.reverse',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // MRD R7.5 provisioning tail (#273): tax families are provisioned with
        // the tenant — no manual `taxes:sync-permissions` run needed for new
        // tenants. SoD: policy.manage is elevated-only.
        $taxPermissions = ['tax.view', 'tax.manage', 'tax.policy.view', 'tax.policy.manage', 'tax.determine'];

        foreach ($taxPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Elevated roles get all accounting permissions
        $elevatedRoles = Role::query()
            ->whereIn('name', ['admin', 'Admin', 'Owner', 'owner', 'super-admin', 'super_admin'])
            ->get();

        foreach ($elevatedRoles as $role) {
            $role->givePermissionTo($permissions);
            $role->givePermissionTo($taxPermissions);
        }

        // Accountant role gets write, post, reverse, and reconciliation (but not lock management)
        $accountantRole = Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
        $accountantRole->givePermissionTo([
            'accounting.write',
            'accounting.post',
            'accounting.reverse',
            'reconciliation.complete',
            'reconciliation.view',
            'reconciliation.import',
            'reconciliation.match',
            'reconciliation.adjust',
            'tax.view',
            'tax.manage',
            'tax.policy.view',
            'tax.determine',
        ]);

        foreach (['manager', 'Manager'] as $managerName) {
            $managerRole = Role::firstOrCreate(['name' => $managerName, 'guard_name' => 'web']);
            $managerRole->givePermissionTo(['tax.view', 'tax.manage', 'tax.policy.view', 'tax.determine']);
        }

        // Billing clerk gets write permission only
        $clerkRole = Role::firstOrCreate(['name' => 'billing_clerk', 'guard_name' => 'web']);
        $clerkRole->givePermissionTo(['accounting.write']);

        // Viewer gets no accounting permissions, but may read tax rules.
        $viewerRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
        $viewerRole->givePermissionTo('tax.view');

        if (isset($this->command)) {
            $this->command->info('Accounting permissions seeded: '.count($permissions).' permissions.');
        }
    }
}
