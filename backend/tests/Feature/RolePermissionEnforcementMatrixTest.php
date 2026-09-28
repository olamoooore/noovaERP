<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Role-permission enforcement, exercised through the real router/middleware
 * stack (never source-string assertions or direct middleware calls):
 *
 *   HTTP -> auth -> SetTenantFromUserPreference -> EnsureTenantSearchPath
 *   -> SyncTenantUserFromCentralAuth -> RequirePermission
 *   -> TenantRoleService (user_company_access.role -> tenant Spatie role)
 *
 * Covers:
 *  1. The seeded matrix's allow/deny mapping for representative endpoints.
 *  2. Role changes (PUT /tenant-users/{id}/role) propagating to enforcement.
 *  3. The editable Roles & Permissions matrix (PUT /tenant-roles/{role})
 *     applying immediately AND surviving a RolePermissionSeeder re-run
 *     (tenants:seed on deploy must not revert tenant customizations).
 *  4. The Owner/Admin-only guard on /tenant-roles, including for Manager
 *     accounts that hold users.write.
 */
class RolePermissionEnforcementMatrixTest extends TenantTestCase
{
    private string $tenantId;

    private User $owner;

    private User $manager;

    private User $sales;

    private User $cashier;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'RBAC Matrix Tenant',
            'email' => 'rbac-matrix@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->manager = User::factory()->create();
        $this->sales = User::factory()->create();
        $this->cashier = User::factory()->create();
        $this->accountant = User::factory()->create();

        foreach ([[$this->owner, 'owner'], [$this->manager, 'manager'], [$this->sales, 'sales'], [$this->cashier, 'cashier'], [$this->accountant, 'accountant']] as [$user, $role]) {
            DB::table('user_company_access')->insert([
                'user_id' => $user->id,
                'tenant_id' => $this->tenantId,
                'role' => $role,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @test */
    public function seeded_matrix_allow_deny_mapping_holds_through_real_http(): void
    {
        // products.view: everyone except Cashier.
        $this->actingAs($this->sales)->getJson('/api/v1/products?per_page=1')->assertOk();
        $this->actingAs($this->cashier)->getJson('/api/v1/products?per_page=1')->assertForbidden();

        // reports.view: Sales and Cashier are NOT in the seeded role list.
        $this->actingAs($this->accountant)->getJson($this->valuationUri())->assertOk();
        $this->actingAs($this->sales)->getJson($this->valuationUri())->assertForbidden();
        $this->actingAs($this->cashier)->getJson($this->valuationUri())->assertForbidden();

        // Owner bypasses fine-grained checks by design (super role).
        $this->actingAs($this->owner)->getJson($this->valuationUri())->assertOk();
    }

    /** @test */
    public function role_change_propagates_to_enforcement_on_the_next_request(): void
    {
        // Sales can currently read products.
        $this->actingAs($this->sales)->getJson('/api/v1/products?per_page=1')->assertOk();

        $this->actingAs($this->owner)
            ->putJson("/api/v1/tenant-users/{$this->sales->id}/role", ['role' => 'cashier'])
            ->assertOk();

        // Same user, same route: now denied because Cashier lacks products.view.
        $this->actingAs($this->sales)->getJson('/api/v1/products?per_page=1')->assertForbidden();

        $this->actingAs($this->owner)
            ->putJson("/api/v1/tenant-users/{$this->sales->id}/role", ['role' => 'sales'])
            ->assertOk();

        $this->actingAs($this->sales)->getJson('/api/v1/products?per_page=1')->assertOk();
    }

    /** @test */
    public function tenant_role_permission_edits_apply_and_survive_reseeding(): void
    {
        $defaults = RolePermissionSeeder::defaultPermissionsFor(UserRole::ACCOUNTANT);
        $this->assertContains('reports.view', $defaults);

        $withoutReports = array_values(array_diff($defaults, ['reports.view']));

        $this->actingAs($this->owner)
            ->putJson('/api/v1/tenant-roles/accountant', ['permissions' => $withoutReports])
            ->assertOk();

        // Enforcement flips immediately.
        $this->actingAs($this->accountant)->getJson($this->valuationUri())->assertForbidden();

        // The role is marked as tenant-customized.
        $this->assertTrue($this->customizedRoles() !== [] && in_array('accountant', $this->customizedRoles(), true));

        // Re-running the seeder (what `tenants:seed` does on deploy) must NOT
        // revert the tenant's own configuration.
        $this->seed(RolePermissionSeeder::class);
        $this->actingAs($this->accountant)->getJson($this->valuationUri())->assertForbidden();

        // Restoring the matrix default clears the marker and re-allows access.
        $this->actingAs($this->owner)
            ->putJson('/api/v1/tenant-roles/accountant', ['permissions' => $defaults])
            ->assertOk();

        $this->actingAs($this->accountant)->getJson($this->valuationUri())->assertOk();
        $this->assertNotContains('accountant', $this->customizedRoles());
    }

    /** @test */
    public function tenant_roles_api_rejects_managers_despite_users_write(): void
    {
        $roles = app(\App\Services\TenantRoleService::class);
        $this->assertTrue(
            $roles->hasPermission($this->manager->id, $this->tenantId, 'users.write'),
            'Manager must hold users.write for this guard test to be meaningful.'
        );

        $this->actingAs($this->manager)->getJson('/api/v1/tenant-roles')->assertForbidden();
        $this->actingAs($this->manager)
            ->putJson('/api/v1/tenant-roles/sales', ['permissions' => []])
            ->assertForbidden();

        $this->actingAs($this->owner)->getJson('/api/v1/tenant-roles')->assertOk();
    }

    private function valuationUri(): string
    {
        return '/api/v1/reports/inventory/valuation?start_date=2026-08-31&end_date=2026-09-28';
    }

    /**
     * @return string[]
     */
    private function customizedRoles(): array
    {
        $raw = DB::table('settings')->where('key', 'rbac.customized_roles')->value('value');
        if (! $raw) {
            return [];
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
