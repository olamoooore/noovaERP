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

        // Medical cases — transactional medical-vertical documents (issues
        // #161/#186). Reads follow inventory.view; writes follow inventory.write.
        'medical_cases.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT, UserRole::SALES, UserRole::VIEWER],
        'medical_cases.write' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],

        // Bonus schemes — pricing/reward configuration (issues #161/#186).
        // Writes are deliberately Manager+; Sales can read schemes but not
        // create/activate/deactivate them or suspend/reactivate assignments.
        'bonus_schemes.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT, UserRole::SALES, UserRole::VIEWER],
        'bonus_schemes.write' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],

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

        // Quotations — sales documents (no ledger entry on confirm), so the
        // preparers keep access; the accounting SOD gate lives on the ledger
        // release actions of the documents a quotation spawns (invoice post,
        // DO confirm) and remains accounting.post. Mappings mirror invoices.*.
        'quotations.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT, UserRole::CASHIER, UserRole::VIEWER],
        'quotations.write' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT],

        // Invoices — standardized flow (MRD invoice-standardized-flow-mrd.md §7.1).
        // invoices.post is intentionally absent: posting reuses accounting.post
        // (EnsureAccountingPostAccess) rather than forking a parallel permission.
        'invoices.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT, UserRole::CASHIER, UserRole::VIEWER],
        'invoices.write' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT],
        'invoices.submit' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT],
        // SoD is enforced at action time (requester ≠ approver), not by role design.
        'invoices.approve' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT],
        'invoices.send' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT],
        'invoices.evidence.manage' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT],
        'invoices.request_payment' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::ACCOUNTANT],

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
        // Hostile re-review (#116): HR profile data (salary, bank details) is
        // sensitive — Sales/Viewer must neither write nor read it.
        'contacts.profiles.hr' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'contacts.delete' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'contacts.merge' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'contacts.visibility.manage' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER],
        'contacts.bank_accounts.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::ACCOUNTANT],
        'contacts.bank_accounts.edit' => [UserRole::OWNER, UserRole::ADMIN, UserRole::ACCOUNTANT],

        // Accounting
        'accounting.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT, UserRole::VIEWER],
        'accounting.write' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT],
        // Posting sell-side documents (invoice post/send, delivery-order
        // confirm, quotation confirm) is a segregation-of-duties action:
        // Owner/Admin (implicit) + Accountant only. Managers and sales staff
        // prepare documents but cannot release them to the ledger.
        'accounting.post' => [UserRole::OWNER, UserRole::ADMIN, UserRole::ACCOUNTANT],
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

        // Storefront (generic commerce module — MRD ecommerce-Enterprice FR-MOD-03).
        // Coarse platform convention (storefront.view/write/settings) matching the
        // exact RequirePermission strings on modules/Storefront/routes/api.php.
        // settings is Owner/Admin: store policies/notifications and (from P2)
        // gateway/channel configuration are sensitive.
        'storefront.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES, UserRole::VIEWER],
        'storefront.write' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::SALES],
        'storefront.settings' => [UserRole::OWNER, UserRole::ADMIN],

        // Website custom-domain ownership and routing are tenant-structural settings.
        'website.domains.view' => [UserRole::OWNER, UserRole::ADMIN],
        'website.domains.create' => [UserRole::OWNER, UserRole::ADMIN],
        'website.domains.verify' => [UserRole::OWNER, UserRole::ADMIN],
        'website.domains.manage' => [UserRole::OWNER, UserRole::ADMIN],
        'website.domains.delete' => [UserRole::OWNER, UserRole::ADMIN],

        // Reports
        'reports.view' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT, UserRole::VIEWER],
        // Issue #103: reporting configuration/mutations (schedules, exports
        // cleanup, cache, templates) — the read permission alone must not allow
        // these. Accountant keeps manage capability so existing reporting
        // workflows do not regress.
        'reports.manage' => [UserRole::OWNER, UserRole::ADMIN, UserRole::MANAGER, UserRole::ACCOUNTANT],

        // Issue #164: legal entity / group / branch / entity-book structure and
        // per-entity module activation. Tenant-structural authority — Owner and
        // Admin only; the routine 'accountant' role must NOT reshape the org
        // chart or switch modules on/off per entity.
        'entities.manage' => [UserRole::OWNER, UserRole::ADMIN],

        // Settings
        'settings.view' => [UserRole::OWNER, UserRole::ADMIN],
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
        // Reversing a posted document back to draft rewrites the ledger and
        // restores stock — restricted to the two administrative roles.
        'documents.reset_to_draft' => [UserRole::OWNER, UserRole::ADMIN],
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

    /**
     * The permission names the matrix grants to a role — the canonical
     * default used by the Roles & Permissions editor to decide whether a
     * tenant customization has been reverted to the default set.
     *
     * @return string[]
     */
    public static function defaultPermissionsFor(UserRole $role): array
    {
        return array_keys(array_filter(
            self::PERMISSION_MATRIX,
            fn (array $roles) => in_array($role, $roles, true),
        ));
    }

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
        // Tenant admins can edit role permissions through Team Members →
        // Roles & Permissions. Those roles are marked in the tenant settings;
        // skip them entirely so re-seeding a tenant does not revert the
        // tenant's own configuration.
        $customizedRoles = array_map(
            fn (string $value) => UserRole::tryFrom($value)?->spatieName(),
            app(\App\Services\RoleCustomizationService::class)->customizedRoles(),
        );
        $customizedRoles = array_filter($customizedRoles);

        foreach (self::PERMISSION_MATRIX as $permissionName => $roleEnums) {
            $permission = Permission::findByName($permissionName, 'web');

            if (! $permission) {
                continue;
            }

            $allowedRoleNames = array_map(fn (UserRole $r) => $r->spatieName(), $roleEnums);

            $rolesToGrant = Role::whereIn('name', $allowedRoleNames)
                ->where('guard_name', 'web')
                ->get();

            foreach ($rolesToGrant as $role) {
                if (in_array($role->name, $customizedRoles, true)) {
                    continue;
                }
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }

            // Revoke stale grants: if a role was previously allowed but has
            // since been dropped from the matrix (e.g. settings.view used to
            // include MANAGER + VIEWER and now only includes OWNER + ADMIN),
            // remove it so re-seeding converges the DB to the source of truth.
            $staleRoles = Role::where('guard_name', 'web')
                ->whereNotIn('name', $allowedRoleNames)
                ->get();

            foreach ($staleRoles as $role) {
                if (in_array($role->name, $customizedRoles, true)) {
                    continue;
                }
                if ($role->hasPermissionTo($permission)) {
                    $role->revokePermissionTo($permission);
                }
            }
        }
    }
}
