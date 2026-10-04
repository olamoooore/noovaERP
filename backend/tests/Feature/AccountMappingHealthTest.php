<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #775 (AU2) — a read-only health check of the tenant's account-role
 * mappings (unmapped roles, wrong-typed accounts, inactive accounts) and the
 * explicit remap fix, through the real routes.
 */
class AccountMappingHealthTest extends TenantTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withMiddleware(
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
        );

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);
    }

    public function test_health_lists_unmapped_roles_and_the_remap_fix_clears_them(): void
    {
        // The resolver lazily seeds every default mapping on first use; the
        // health check targets tenants with gaps, so clear them first.
        DB::table('account_mappings')->delete();

        $cash = ChartOfAccount::factory()->create(['code' => 'AH-CASH-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true]);

        $health = $this->actingAs($this->admin)
            ->getJson('/api/v1/accounting/account-mappings/health')
            ->assertOk();

        $unmapped = collect($health->json('data.unmapped'))->pluck('mapping_key');
        $this->assertContains('cash', $unmapped, 'a role with no mapping must be listed');
        $this->assertGreaterThan(0, (int) $health->json('data.summary.unmapped'));

        $this->actingAs($this->admin)
            ->putJson('/api/v1/accounting/account-mappings/cash', ['account_id' => $cash->id])
            ->assertOk()
            ->assertJsonPath('data.mapping_key', 'cash')
            ->assertJsonPath('data.account_id', $cash->id);

        $after = $this->actingAs($this->admin)
            ->getJson('/api/v1/accounting/account-mappings/health')
            ->assertOk();

        $unmappedAfter = collect($after->json('data.unmapped'))->pluck('mapping_key');
        $this->assertNotContains('cash', $unmappedAfter, 'the mapped role must leave the unmapped list');
    }

    public function test_health_flags_a_role_mapped_to_the_wrong_account_type(): void
    {
        $assetAccount = ChartOfAccount::factory()->create(['code' => 'AH-WRONG-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true]);

        // sales_revenue expects a revenue account; point it at an asset.
        $this->actingAs($this->admin)
            ->putJson('/api/v1/accounting/account-mappings/sales_revenue', ['account_id' => $assetAccount->id])
            ->assertOk();

        $health = $this->actingAs($this->admin)
            ->getJson('/api/v1/accounting/account-mappings/health')
            ->assertOk();

        $wrong = collect($health->json('data.wrong_type'))->firstWhere('mapping_key', 'sales_revenue');
        $this->assertNotNull($wrong, 'a wrong-typed mapping must be flagged');
        $this->assertSame('revenue', $wrong['expected_type']);
        $this->assertSame('asset', $wrong['account_type']);
    }
}
