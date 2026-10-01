<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\SyncTaxPermissions;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #273 provisioning tail: new tenants get the tax permission families from
 * the tenant seeder; existing tenants get them via taxes:sync-permissions —
 * with tax.policy.manage reserved for elevated roles (SoD).
 */
class TaxPermissionProvisioningTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
    }

    public function test_tenant_seeder_provisions_tax_families_with_separation_of_duties(): void
    {
        foreach (['admin', 'accountant', 'manager', 'viewer'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->seed(\Database\Seeders\AccountingPermissionSeeder::class);

        foreach (['tax.view', 'tax.manage', 'tax.policy.view', 'tax.policy.manage', 'tax.determine'] as $name) {
            $this->assertTrue(
                Permission::where('name', $name)->where('guard_name', 'web')->exists(),
                "Provisioning must create {$name}."
            );
        }

        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('tax.policy.manage'));
        $this->assertTrue(Role::findByName('accountant')->hasPermissionTo('tax.determine'));
        $this->assertFalse(
            Role::findByName('accountant')->hasPermissionTo('tax.policy.manage'),
            'Routine accounting roles must not self-activate tax rules (SoD).'
        );
        $this->assertFalse(Role::findByName('viewer')->hasPermissionTo('tax.manage'));
        $this->assertTrue(Role::findByName('viewer')->hasPermissionTo('tax.view'));
    }

    public function test_backfill_command_grants_new_families_to_existing_tenants(): void
    {
        $viewerish = Role::firstOrCreate(['name' => 'viewerish', 'guard_name' => 'web']);
        $viewerish->givePermissionTo(Permission::firstOrCreate(['name' => 'tax.view', 'guard_name' => 'web']));

        $managerish = Role::firstOrCreate(['name' => 'managerish', 'guard_name' => 'web']);
        $managerish->givePermissionTo(Permission::firstOrCreate(['name' => 'tax.manage', 'guard_name' => 'web']));

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        app(SyncTaxPermissions::class)->syncCurrentSchema();

        $this->assertTrue($viewerish->refresh()->hasPermissionTo('tax.policy.view'), 'tax.view holders get policy.view.');
        $this->assertTrue($managerish->refresh()->hasPermissionTo('tax.determine'), 'tax.manage holders get determine.');
        $this->assertFalse(
            $managerish->hasPermissionTo('tax.policy.manage'),
            'Non-elevated tax.manage holders must NOT receive policy.manage.'
        );
        $this->assertTrue($admin->refresh()->hasPermissionTo('tax.policy.manage'), 'Elevated roles get policy.manage.');

        $this->assertSame(
            ['tax.policy.manage', 1],
            ['tax.policy.manage', (int) DB::table('role_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                ->where('permissions.name', 'tax.policy.manage')
                ->where('role_has_permissions.role_id', $admin->id)
                ->count()],
        );
    }
}