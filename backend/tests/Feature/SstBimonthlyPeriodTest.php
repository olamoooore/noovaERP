<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #741 T8: SST-02 needs its real bimonthly taxable period; pack-declared
 * period shapes; and explicit rule-type classification where anything that is
 * not sales/service is unclassified and blocks filing.
 */
class SstBimonthlyPeriodTest extends TenantTestCase
{
    private User $user;

    private int $entryId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@sstbim.test']);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->user);

        $this->entryId = DB::table('journal_entries')->insertGetId([
            'entry_number' => 'JE-SSTB-'.Str::lower(Str::random(10)),
            'entry_date' => now()->toDateString(),
            'type' => 'invoice',
            'status' => 'posted',
            'posted_at' => now(),
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rule(string $type): Tax
    {
        return Tax::query()->firstOrCreate(
            ['code' => 'SB-'.strtoupper($type)],
            ['name' => 'SB '.$type, 'type' => $type, 'jurisdiction' => 'MY', 'rate' => 8, 'is_active' => true],
        );
    }

    private function trail(string $period, float $taxable, float $tax, Tax $rule): void
    {
        DB::table('tax_transaction_details')->insert([
            'journal_entry_id' => $this->entryId,
            'source_document_type' => 'invoice',
            'source_document_id' => 0,
            'tax_code' => $rule->code,
            'tax_rule_id' => $rule->id,
            'tax_rate' => $rule->rate,
            'tax_treatment' => 'standard_rated',
            'tax_regime' => 'SST',
            'taxable_amount' => $taxable,
            'tax_amount' => $tax,
            'reporting_period' => $period,
            'jurisdiction' => 'MY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_sst02_for_jan_feb_aggregates_exactly_those_two_months(): void
    {
        $service = $this->rule('service');
        $this->trail('2025-12', 111.0, 8.88, $service);
        $this->trail('2026-01', 100.0, 8.00, $service);
        $this->trail('2026-02', 200.0, 16.00, $service);
        $this->trail('2026-03', 444.0, 35.52, $service);

        $response = $this->getJson('/api/v1/accounting/statutory-returns/MY/2026-B1');

        $response->assertOk();
        $this->assertSame(['2026-01', '2026-02'], $response->json('data.reporting_period.months'));
        $this->assertSame(300.0, (float) $response->json('data.service_tax.taxable_value'), 'B1 must cover Jan+Feb only.');
        $this->assertSame(24.0, (float) $response->json('data.service_tax.tax_due'));
        $this->assertSame(0.0, (float) $response->json('data.sales_tax.taxable_value'));
    }

    public function test_non_sales_service_rule_type_is_unclassified_and_blocks_filing(): void
    {
        $service = $this->rule('service');
        $gstTyped = $this->rule('gst');
        $this->trail('2026-01', 100.0, 8.00, $service);
        $this->trail('2026-01', 500.0, 50.00, $gstTyped);

        $show = $this->getJson('/api/v1/accounting/statutory-returns/MY/2026-B1');
        $show->assertOk();
        $this->assertSame(1, (int) $show->json('data.classification.unclassified_rows'), 'A typed rule that is neither sales nor service must be unclassified.');
        $this->assertSame(100.0, (float) $show->json('data.service_tax.taxable_value'));
        $this->assertSame(0.0, (float) $show->json('data.sales_tax.taxable_value'), 'The gst-typed row must not be bucketed as sales tax.');

        $file = $this->postJson('/api/v1/accounting/statutory-returns/MY/2026-B1/file');
        $file->assertStatus(422);
        $this->assertStringContainsString('unclassified', strtolower((string) $file->json('message')));
    }

    public function test_clean_return_files_and_period_shapes_are_pack_defined(): void
    {
        $service = $this->rule('service');
        $this->trail('2026-01', 100.0, 8.00, $service);
        $this->trail('2026-02', 200.0, 16.00, $service);

        $this->postJson('/api/v1/accounting/statutory-returns/MY/2026-B1/file')->assertCreated();

        // MY declares monthly + bimonthly — not quarterly.
        $this->getJson('/api/v1/accounting/statutory-returns/MY/2026-Q1')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Period shape quarterly is not supported by the MY pack.');

        // SG declares monthly + quarterly — not bimonthly.
        $this->getJson('/api/v1/accounting/statutory-returns/SG/2026-B1')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Period shape bimonthly is not supported by the SG pack.');
    }

    public function test_registration_start_month_shifts_the_bimonthly_blocks(): void
    {
        $service = $this->rule('service');
        $this->trail('2026-01', 500.0, 50.00, $service);
        $this->trail('2026-07', 100.0, 8.00, $service);
        $this->trail('2026-08', 200.0, 16.00, $service);

        $store = $this->postJson('/api/v1/accounting/tax-registrations', [
            'jurisdiction' => 'my',
            'regime' => 'sst',
            'registration_number' => 'W10-2026-000777',
            'effective_from' => '2026-01-01',
            'filing_start_month' => 7,
        ]);
        $store->assertCreated();
        $this->assertSame(7, (int) $store->json('data.filing_start_month'));

        $response = $this->getJson('/api/v1/accounting/statutory-returns/MY/2026-B1');

        $response->assertOk();
        $this->assertSame(['2026-07', '2026-08'], $response->json('data.reporting_period.months'), 'With cycle start month 7, B1 is Jul–Aug.');
        $this->assertSame(300.0, (float) $response->json('data.service_tax.taxable_value'));
    }
}
