<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seed standard ERP roles and permissions.
 *
 * Run in BOTH public schema (central reference) and tenant schemas.
 * Idempotent — safe to re-run.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Permission definitions: [name => [roles]]
     */
    private const PERMISSION_MATRIX = [
        // Dashboard
        'dashboard.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT, UserRole::CASHIER, UserRole::VIEWER],

        // POS
        'pos.view'     => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::CASHIER, UserRole::VIEWER],
        'pos.create'   => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::CASHIER],
        'pos.settings' => [UserRole::OWNER, UserRole::ADMIN],

        // Inventory
        'inventory.view'     => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT, UserRole::VIEWER],
        'inventory.write'    => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'inventory.settings' => [UserRole::OWNER, UserRole::ADMIN],

        // Sales
        'sales.view'     => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::VIEWER],
        'sales.write'    => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'sales.settings' => [UserRole::OWNER, UserRole::ADMIN],

        // Accounting
        'accounting.view'     => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT, UserRole::VIEWER],
        'accounting.write'    => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT],
        'accounting.post'     => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT],
        'accounting.reverse'  => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT],
        'accounting.lock'     => [UserRole::OWNER, UserRole::ADMIN],
        'accounting.settings' => [UserRole::OWNER, UserRole::ADMIN],
        'reconciliation.complete' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT],

        // HRM
        'hrm.view'     => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::VIEWER],
        'hrm.write'    => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'hrm.settings' => [UserRole::OWNER, UserRole::ADMIN],

        // Loyalty
        'loyalty.view'     => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::VIEWER],
        'loyalty.write'    => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'loyalty.settings' => [UserRole::OWNER, UserRole::ADMIN],

        // Reports
        'reports.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT, UserRole::VIEWER],

        // Settings
        'settings.view'  => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::VIEWER],
        'settings.write' => [UserRole::OWNER, UserRole::ADMIN],

        // Users
        'users.view'   => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::VIEWER],
        'users.write'  => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'users.delete' => [UserRole::OWNER, UserRole::ADMIN],
    ];

    public function run(): void
    {
        // Ensure Spatie tables exist before seeding
        if (! $this->tablesExist()) {
            $this->command?->warn('Spatie permission tables not found. Skipping RolePermissionSeeder.');
            return;
        }

        $this->seedRoles();
        $this->seedPermissions();
        $this->syncRolePermissions();

        $this->command?->info('RolePermissionSeeder completed successfully.');
    }

    private function tablesExist(): bool
    {
        $driver = DB::connection()->getDriverName();
        $schema = $driver === 'pgsql' ? DB::getSchemaBuilder() : DB::getSchemaBuilder();

        return $schema->hasTable('roles') && $schema->hasTable('permissions');
    }

    private function seedRoles(): void
    {
        foreach (UserRole::cases() as $role) {
            Role::firstOrCreate(
                ['name' => $role->spatieName(), 'guard_name' => 'web'],
                ['name' => $role->spatieName()]
            );
        }
    }

    private function seedPermissions(): void
    {
        foreach (array_keys(self::PERMISSION_MATRIX) as $permissionName) {
            Permission::firstOrCreate(
                ['name' => $permissionName, 'guard_name' => 'web'],
                ['name' => $permissionName]
            );
        }
    }

    private function syncRolePermissions(): void
    {
        foreach (self::PERMISSION_MATRIX as $permissionName => $roleEnums) {
            $permission = Permission::findByName($permissionName, 'web');

            if (! $permission) {
                continue;
            }

            $roleNames = array_map(fn (UserRole $r) => $r->spatieName(), $roleEnums);
            $roles = Role::whereIn('name', $roleNames)->where('guard_name', 'web')->get();

            foreach ($roles as $role) {
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }
    }
}
