<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AccountingCountry\Services\TaxReturnAggregator;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #750 TU4: the filing periods come from the registration's frequency and
 * cycle start month (#741), never free-typed — MY two-monthly periods honour
 * the start month, SG quarterly is quarters, and a filed period shows its
 * status.
 */
class RegistrationDrivenPeriodsTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@periods.test']);
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function register(string $jurisdiction, string $frequency, ?int $startMonth = null): void
    {
        $this->postJson('/api/v1/accounting/tax-registrations', array_filter([
            'jurisdiction' => $jurisdiction,
            'registration_number' => strtoupper($jurisdiction).'-'.Str::random(8),
            'effective_from' => '2024-01-01',
            'filing_frequency' => $frequency,
            'filing_start_month' => $startMonth,
        ], fn ($v) => $v !== null))->assertCreated();
    }

    public function test_bimonthly_registration_drives_two_month_periods(): void
    {
        $this->register('MY', 'bimonthly', 4);

        $response = $this->getJson('/api/v1/accounting/statutory-returns/periods?country=MY')->assertOk();

        $periods = collect((array) $response->json('data'));
        $this->assertNotEmpty($periods);
        $this->assertMatchesRegularExpression('/^\d{4}-B[1-6]$/', $periods->first()['period']);

        // The current period must actually contain the current month.
        $months = TaxReturnAggregator::periodMonths($periods->first()['period'], 'MY', null);
        $this->assertContains(now()->format('Y-m'), $months, 'The listed period must cover the current month.');
    }

    public function test_quarterly_registration_drives_quarter_periods(): void
    {
        $this->register('SG', 'quarterly');

        $response = $this->getJson('/api/v1/accounting/statutory-returns/periods?country=SG')->assertOk();

        $period = $response->json('data.0.period');
        $this->assertMatchesRegularExpression('/^\d{4}-Q[1-4]$/', $period);
        $this->assertContains(now()->format('Y-m'), TaxReturnAggregator::periodMonths($period, 'SG', null));
    }

    public function test_a_filed_period_is_marked_filed(): void
    {
        $this->register('SG', 'quarterly');

        $period = $this->getJson('/api/v1/accounting/statutory-returns/periods?country=SG')->json('data.0.period');

        DB::table('statutory_return_filings')->insert([
            'tenant_id' => tenant()->id,
            'country' => 'SG', 'form' => 'GST F5', 'period' => $period, 'entity_key' => 0,
            'filing_version' => 1, 'boxes' => json_encode([]),
            'filed_by' => $this->user->id, 'filed_at' => now(),
            'state' => 'PREPARED', 'state_updated_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = collect((array) $this->getJson('/api/v1/accounting/statutory-returns/periods?country=SG')->json('data'));
        $this->assertSame('Filed', $rows->firstWhere('period', $period)['status']);
    }

    public function test_without_a_country_the_legacy_activity_list_is_returned(): void
    {
        $this->register('SG', 'quarterly');

        $response = $this->getJson('/api/v1/accounting/statutory-returns/periods')->assertOk();
        $this->assertIsArray($response->json('data'));
    }
}
