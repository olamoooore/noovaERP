<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #768 (A8) — fiscal periods were calendar-only and tenant-wide: a
 * legal entity with FYE 30 June got January–December periods, the entity's
 * fiscal_year_start was ignored, two entities with different year ends could
 * not coexist, and the 365-day report cap rejected a long first financial
 * year (up to 18 months is allowed for MY/SG companies).
 *
 * Real HTTP through the router; period generation must read the legal
 * entity (the single source of truth) and reports must allow the entity's
 * own fiscal year however long it is.
 */
class FiscalYearPerEntityTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private int $juneEntityId;

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
            'base_currency' => 'MYR', 'fiscal_year_start' => '07-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->juneEntityId = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'June Year End Sdn Bhd', 'code' => 'JUN-'.Str::upper(Str::random(4)),
            'country_code' => 'MY', 'base_currency' => 'MYR', 'fiscal_year_start' => '07-01',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createDecemberEntity(): int
    {
        $groupId = DB::table('legal_entities')->where('id', $this->juneEntityId)->value('group_id');

        return DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'December Year End Sdn Bhd', 'code' => 'DEC-'.Str::upper(Str::random(4)),
            'country_code' => 'MY', 'base_currency' => 'MYR', 'fiscal_year_start' => '01-01',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function periodsFor(int $entityId)
    {
        return DB::table('fiscal_periods')
            ->where('legal_entity_id', $entityId)
            ->orderBy('period_start')
            ->get();
    }

    public function test_generate_year_uses_the_entities_july_to_june_calendar(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/accounting/fiscal-periods/generate-year', [
                'fiscal_year' => 2026,
                'legal_entity_id' => $this->juneEntityId,
            ])
            ->assertOk();

        $periods = $this->periodsFor($this->juneEntityId);

        $this->assertCount(12, $periods, 'FYE 30 June must produce twelve monthly periods');
        $this->assertSame('2026-07-01', $periods->first()->period_start);
        $this->assertSame('2027-06-30', $periods->last()->period_end);
        $this->assertSame(
            '2026-07',
            $periods->first()->code,
            'entity periods must be identifiable per entity'
        );
        $this->assertFalse(
            DB::table('fiscal_periods')
                ->where('legal_entity_id', $this->juneEntityId)
                ->where('period_start', '2026-01-01')
                ->exists(),
            'a July-start entity must not get a January period'
        );
    }

    public function test_two_entities_with_different_year_ends_coexist(): void
    {
        $decemberEntityId = $this->createDecemberEntity();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/accounting/fiscal-periods/generate-year', [
                'fiscal_year' => 2026,
                'legal_entity_id' => $this->juneEntityId,
            ])
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/accounting/fiscal-periods/generate-year', [
                'fiscal_year' => 2026,
                'legal_entity_id' => $decemberEntityId,
            ])
            ->assertOk();

        $june = $this->periodsFor($this->juneEntityId);
        $december = $this->periodsFor($decemberEntityId);

        $this->assertCount(12, $june);
        $this->assertCount(12, $december);
        $this->assertSame('2026-07-01', $june->first()->period_start);
        $this->assertSame('2026-01-01', $december->first()->period_start);
        $this->assertSame('2026-12-31', $december->last()->period_end);
    }

    public function test_a_first_year_can_run_eighteen_months_and_be_reported(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/accounting/fiscal-periods/generate-year', [
                'fiscal_year' => 2026,
                'legal_entity_id' => $this->juneEntityId,
                'period_start' => '2026-01-01',
                'period_end' => '2027-06-30',
            ])
            ->assertOk();

        $periods = $this->periodsFor($this->juneEntityId);

        $this->assertCount(18, $periods, 'a first financial year may run up to 18 months');
        $this->assertSame('2026-01-01', $periods->first()->period_start);
        $this->assertSame('2027-06-30', $periods->last()->period_end);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/financial/profit-loss?start_date=2026-01-01&end_date=2027-06-30&legal_entity_id='.$this->juneEntityId)
            ->assertOk();
    }

    public function test_annual_profit_loss_runs_for_the_entities_fiscal_year(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/accounting/fiscal-periods/generate-year', [
                'fiscal_year' => 2026,
                'legal_entity_id' => $this->juneEntityId,
            ])
            ->assertOk();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/financial/profit-loss?start_date=2026-07-01&end_date=2027-06-30&legal_entity_id='.$this->juneEntityId);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('success'));
    }

    public function test_company_settings_fiscal_year_is_derived_from_the_legal_entity(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/accounting/settings/fiscal-year');

        $response->assertOk();

        $today = Carbon::today();
        $start = Carbon::create($today->year, 7, 1);
        if ($start->gt($today)) {
            $start->subYear();
        }

        $this->assertSame($start->format('Y-m-d'), $response->json('data.fiscal_year_start'));
        $this->assertSame($start->copy()->addYear()->subDay()->format('Y-m-d'), $response->json('data.fiscal_year_end'));
    }
}
