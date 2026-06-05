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
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Elevated roles get all accounting permissions
        $elevatedRoles = Role::query()
            ->whereIn('name', ['admin', 'Admin', 'Owner', 'owner', 'super-admin', 'super_admin'])
            ->get();

        foreach ($elevatedRoles as $role) {
            $role->givePermissionTo($permissions);
        }

        // Accountant role gets write, post, reverse, and reconciliation (but not lock management)
        $accountantRole = Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
        $accountantRole->givePermissionTo([
            'accounting.write',
            'accounting.post',
            'accounting.reverse',
            'reconciliation.complete',
        ]);

        // Billing clerk gets write permission only
        $clerkRole = Role::firstOrCreate(['name' => 'billing_clerk', 'guard_name' => 'web']);
        $clerkRole->givePermissionTo(['accounting.write']);

        // Viewer gets no accounting permissions
        Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);

        if (isset($this->command)) {
            $this->command->info('Accounting permissions seeded: ' . count($permissions) . ' permissions.');
        }
    }
}
