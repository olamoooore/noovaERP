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
        'pos.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::CASHIER, UserRole::VIEWER],
        'pos.create' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::CASHIER],
        'pos.settings' => [UserRole::OWNER, UserRole::ADMIN],

        // Inventory
        'inventory.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT, UserRole::SALES, UserRole::VIEWER],
        'inventory.write' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'inventory.settings' => [UserRole::OWNER, UserRole::ADMIN],

        // Product catalogue — destructive bulk purge (issue #127 BLOCKER).
        // Strictest possible grant: Owner/Admin only. This guards
        // DELETE /products/delete-all, which hard-deletes the entire catalogue.
        'products.delete_all' => [UserRole::OWNER, UserRole::ADMIN],

        // Product catalogue CRUD (issue #128 CRITICAL). Granular permissions
        // mirroring the inventory.* role mappings so no legitimate user loses
        // access: products.view == inventory.view roles, products.* write ==
        // inventory.write roles. Export exposes the full catalogue (incl. cost)
        // so it follows the view roles; import mutates data so it follows write.
        'products.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT, UserRole::SALES, UserRole::VIEWER],
        'products.create' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'products.edit' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'products.delete' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'products.export' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT, UserRole::SALES, UserRole::VIEWER],
        'products.import' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],

        // Sales
        'sales.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::VIEWER],
        'sales.write' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'sales.settings' => [UserRole::OWNER, UserRole::ADMIN],

        // Fulfillment / shipping (issue #143 BLOCKER). FulfillmentModuleContract
        // declared fulfillment.* permissions but nothing seeded them, so the
        // RequirePermission wiring had nothing valid to check. Using the coarse
        // platform convention (fulfillment.view/write/settings) per the module's
        // own CLAUDE.md guidance. Carrier-credential CRUD is fulfillment.settings
        // (Owner/Admin only) given its sensitivity (encrypted API keys/secrets).
        'fulfillment.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::VIEWER],
        'fulfillment.write' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'fulfillment.settings' => [UserRole::OWNER, UserRole::ADMIN],

        // Customers / CRM Contacts (ADR-003A CT-VEN-020)
        'customers.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT, UserRole::VIEWER],
        'customers.write' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'customers.delete' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],

        // Contact Master (issue #116 CRITICAL). The contract declared these 18
        // permission strings but none were seeded or enforced. Role mappings
        // mirror customers.* sensitivity: read is broad, write is Manager+,
        // delete/merge/visibility are Manager+, and bank-account data is
        // restricted to Owner/Admin/Accountant given its sensitivity.
        'contacts.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT, UserRole::VIEWER],
        'contacts.search' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT, UserRole::VIEWER],
        'contacts.export' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT, UserRole::VIEWER],
        'contacts.profiles.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT, UserRole::VIEWER],
        'contacts.create' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'contacts.edit' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'contacts.import' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'contacts.roles.manage' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'contacts.identifiers.manage' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'contacts.addresses.manage' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'contacts.methods.manage' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'contacts.relationships.manage' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'contacts.profiles.manage' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT, UserRole::SALES],
        'contacts.delete' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'contacts.merge' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'contacts.visibility.manage' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'contacts.bank_accounts.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::ACCOUNTANT],
        'contacts.bank_accounts.edit' => [UserRole::OWNER, UserRole::ADMIN, UserRole::ACCOUNTANT],

        // Accounting
        'accounting.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT, UserRole::VIEWER],
        'accounting.write' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT],
        'accounting.post' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT],
        'accounting.reverse' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT],
        'accounting.lock' => [UserRole::OWNER, UserRole::ADMIN],
        'accounting.settings' => [UserRole::OWNER, UserRole::ADMIN],
        'reconciliation.complete' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT],
        'reconciliation.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT, UserRole::VIEWER],
        'reconciliation.import' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT],
        'reconciliation.match' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT],
        'reconciliation.adjust' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT],
        'reconciliation.exclude' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'reconciliation.reverse' => [UserRole::OWNER, UserRole::ADMIN],

        // Financial Close (ACC-011)
        'accounting.close.read' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT, UserRole::VIEWER],
        'accounting.close.manage' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT],
        'accounting.close.signoff' => [UserRole::OWNER, UserRole::ADMIN, UserRole::ACCOUNTANT],
        'accounting.close.admin' => [UserRole::OWNER, UserRole::ADMIN],
        'accounting.close.task_write' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT],
        'accounting.close.finding_write' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT],
        'accounting.close.escalation_write' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT],
        'accounting.close.template_write' => [UserRole::OWNER, UserRole::ADMIN],

        // ACC-001 Opening Balance (issue #70)
        'accounting.opening_balance.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT, UserRole::VIEWER],
        'accounting.opening_balance.write' => [UserRole::OWNER, UserRole::ADMIN, UserRole::ACCOUNTANT],
        'accounting.opening_balance.approve' => [UserRole::OWNER, UserRole::ADMIN], // SoD: not same as write
        'accounting.opening_balance.post' => [UserRole::OWNER, UserRole::ADMIN],

        // HRM
        'hrm.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::VIEWER],
        'hrm.write' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'hrm.settings' => [UserRole::OWNER, UserRole::ADMIN],

        // Loyalty
        'loyalty.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::VIEWER],
        'loyalty.write' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'loyalty.settings' => [UserRole::OWNER, UserRole::ADMIN],

        // Reports
        'reports.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT, UserRole::VIEWER],

        // Settings
        'settings.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::VIEWER],
        'settings.write' => [UserRole::OWNER, UserRole::ADMIN],

        // Users
        'users.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::VIEWER],
        'users.write' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'users.delete' => [UserRole::OWNER, UserRole::ADMIN],

        // ADR-004 Universal Template Platform — Designer / Reviewer / Publisher / Assigner segregation (§14).
        // Ordinary Sales/CRM/Accounting users do NOT receive publish or default-assignment automatically.
        // Template authoring
        'template.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT, UserRole::CASHIER, UserRole::VIEWER],
        'template.create' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'template.edit_draft' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'template.duplicate' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'template.validate' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        // Controlled publication (Reviewer / Publisher / Assigner)
        'template.review' => [UserRole::OWNER, UserRole::ADMIN],
        'template.publish' => [UserRole::OWNER, UserRole::ADMIN],
        'template.assign' => [UserRole::OWNER, UserRole::ADMIN],
        'template.archive' => [UserRole::OWNER, UserRole::ADMIN],
        'template.restore' => [UserRole::OWNER, UserRole::ADMIN],
        // Restricted capability
        'template.asset.manage' => [UserRole::OWNER, UserRole::ADMIN],
        'template.custom_code' => [UserRole::OWNER],
        // Documents
        'document.generate' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT],
        'document.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT, UserRole::CASHIER, UserRole::VIEWER],
        'document.regenerate' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'document.distribute' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        // Forms
        'form.open' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT, UserRole::CASHIER, UserRole::VIEWER],
        'form.submit' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT, UserRole::CASHIER, UserRole::VIEWER],
        'form.submission.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'form.approve' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'form.export' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        // Surveys
        'survey.manage' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'survey.respond' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT, UserRole::CASHIER, UserRole::VIEWER],
        'survey.analyse' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'survey.export' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
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
