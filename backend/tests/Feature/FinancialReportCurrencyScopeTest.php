<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #855 (RP4) — financial reports were currency-blind: a tenant-scoped
 * report added an MYR entity's base amounts and an SGD entity's base amounts
 * as if they were one currency.
 *
 * The guard: when the tenant's legal entities have different base currencies
 * and no legal entity is selected, the report endpoints refuse with 422 —
 * amounts are never summed across currencies (AC2). Presentation-currency
 * translation is a separate feature; AC1's per-column/export currency display
 * is likewise not claimed here.
 *
 * Real HTTP through the router.
 */
class FinancialReportCurrencyScopeTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private int $myrEntityId;

    private int $sgdEntityId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withMiddleware(
            \App\Http\Middleware\SetTenantFromUserPreference::class,
            \App\Http\Middleware\EnsureTenantSearchPath::class,
            \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
        );

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Group', 'code' => 'GRP-'.Str::upper(Str::random(4)),
            'base_currency' => 'MYR', 'fiscal_year_start' => '01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->myrEntityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Malaysia Sdn Bhd', 'code' => 'MY-'.Str::upper(Str::random(4)),
            'country_code' => 'MY', 'base_currency' => 'MYR', 'fiscal_year_start' => '01-01',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->sgdEntityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Singapore Pte Ltd', 'code' => 'SG-'.Str::upper(Str::random(4)),
            'country_code' => 'SG', 'base_currency' => 'SGD', 'fiscal_year_start' => '01-01',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function periodQuery(): string
    {
        return '?start_date=2026-09-01&end_date=2026-09-30';
    }

    public function test_a_multi_currency_tenant_gets_422_on_tenant_scoped_reports(): void
    {
        foreach ([
            '/api/v1/reports/financial/profit-loss'.$this->periodQuery(),
            '/api/v1/reports/financial/trial-balance?as_of_date=2026-09-30',
            '/api/v1/reports/financial/balance-sheet?as_of_date=2026-09-30',
            '/api/v1/reports/financial/cash-flow'.$this->periodQuery(),
        ] as $url) {
            $response = $this->actingAs($this->admin)->getJson($url);
            $response->assertStatus(422, "A multi-currency tenant must not get a raw sum from {$url}");
            $this->assertStringContainsString('different base currencies', (string) $response->json('error'));
            $this->assertStringContainsString('MYR', (string) $response->json('error'));
            $this->assertStringContainsString('SGD', (string) $response->json('error'));
        }
    }

    public function test_selecting_a_legal_entity_makes_the_scope_valid(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/financial/profit-loss'.$this->periodQuery().'&legal_entity_id='.$this->myrEntityId)
            ->assertOk();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/financial/balance-sheet?as_of_date=2026-09-30&legal_entity_id='.$this->sgdEntityId)
            ->assertOk();
    }

    public function test_a_single_currency_tenant_is_unaffected(): void
    {
        // Make both entities the same currency: no ambiguity, no guard.
        DB::table('legal_entities')->where('id', $this->sgdEntityId)->update(['base_currency' => 'MYR']);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/financial/profit-loss'.$this->periodQuery())
            ->assertOk();
    }
}
