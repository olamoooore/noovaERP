<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * #737 T4: registration-threshold monitoring must measure SALES REVENUE —
 * posted invoices less posted credit notes, excluding exempt and out-of-scope
 * supplies, per legal entity, whether or not tax was charged — over the last
 * 12 COMPLETE months (backward) with a separate forecast-based forward check.
 *
 * Repro: the old monitor summed the tax trail with an undefined `$v`, so it
 * always reported 0 and could never flag an unregistered entity.
 */
class ThresholdMonitorTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@threshold.test']);
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();

        // Home jurisdiction: MY — an entity not yet registered for service tax.
        Setting::setValue('tax', ['jurisdiction' => 'MY']);
    }

    private function postedJournal(int|string|null $legalEntityId = null): int
    {
        return DB::table('journal_entries')->insertGetId([
            'entry_number' => 'JE-TH-'.Str::lower(Str::random(12)),
            'entry_date' => now()->toDateString(),
            'type' => 'invoice',
            'status' => 'posted',
            'posted_at' => now(),
            'created_by' => $this->user->id,
            'legal_entity_id' => $legalEntityId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function postedInvoice(string $date, float $net, ?string $treatment = null, int|string|null $legalEntityId = null): Invoice
    {
        return Invoice::factory()->create([
            'invoice_date' => $date,
            'due_date' => $date,
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'journal_entry_id' => $this->postedJournal($legalEntityId),
            'subtotal' => $net,
            'total' => $net,
            'balance' => $net,
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
            // tax_jurisdiction / tax_treatment stay null: never determined (untaxed).
            'tax_treatment' => $treatment,
        ]);
    }

    public function test_untaxed_sales_revenue_is_measured_and_flagged_against_the_threshold(): void
    {
        // 6 × RM100k posted service invoices inside the last 12 complete months.
        foreach ([2, 3, 4, 5, 6, 7] as $monthsAgo) {
            $this->postedInvoice(now()->startOfMonth()->subMonthsNoOverflow($monthsAgo)->addDays(10)->toDateString(), 100000.00);
        }
        // The current (partial) month must not count — nor may it pull the window forward.
        $this->postedInvoice(now()->startOfMonth()->addDay()->toDateString(), 1000000.00);
        // Exempt supplies are outside the registration base.
        $this->postedInvoice(now()->startOfMonth()->subMonthsNoOverflow(4)->addDays(3)->toDateString(), 999999.00, 'exempt');

        // A posted credit note reduces the base.
        $credited = Invoice::query()->orderBy('id')->firstOrFail();
        DB::table('customer_credit_notes')->insert([
            'credit_note_number' => 'CN-TH-'.Str::lower(Str::random(8)),
            'customer_id' => $this->customer->id,
            'invoice_id' => $credited->id,
            'credit_note_date' => now()->startOfMonth()->subMonthsNoOverflow(4)->addDays(20)->toDateString(),
            'status' => 'posted',
            'subtotal' => 50000.00,
            'total_amount' => 50000.00,
            'journal_entry_id' => $this->postedJournal(),
            'created_by' => $this->user->id,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/tax-engine/thresholds?jurisdiction=MY');

        $response->assertOk()
            // 600k − 50k credit note = 550k taxable services base; current month and exempt excluded.
            ->assertJsonPath('data.MY.thresholds.0.monitoring_flag', true);
        $this->assertSame(550000.0, (float) $response->json('data.MY.trailing_12m_taxable_value'));
        $this->assertSame(500000.0, (float) $response->json('data.MY.thresholds.0.amount'));

        $expectedWindow = [
            now()->startOfMonth()->subMonthNoOverflow()->subMonthNoOverflow(11)->format('Y-m'),
            now()->startOfMonth()->subMonthNoOverflow()->format('Y-m'),
        ];
        $this->assertSame($expectedWindow, $response->json('data.MY.months_window'));
    }

    public function test_forecast_is_a_separate_forward_looking_check(): void
    {
        // No revenue at all: backward stays false; a big forecast flips only the forward flag.
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/tax-engine/thresholds?jurisdiction=MY&forecast_next_12m=700000');

        $response->assertOk()
            ->assertJsonPath('data.MY.thresholds.0.monitoring_flag', false)
            ->assertJsonPath('data.MY.thresholds.0.forward_flag', true);
        $this->assertSame(0.0, (float) $response->json('data.MY.trailing_12m_taxable_value'));
        $this->assertSame(700000.0, (float) $response->json('data.MY.forecast_next_12m'));

        $below = $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/tax-engine/thresholds?jurisdiction=MY&forecast_next_12m=400000');

        $below->assertOk()
            ->assertJsonPath('data.MY.thresholds.0.monitoring_flag', false)
            ->assertJsonPath('data.MY.thresholds.0.forward_flag', false);
    }

    public function test_turnover_is_scoped_to_the_requested_legal_entity(): void
    {
        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'TH Group', 'code' => 'TH-G', 'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01', 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity A', 'code' => 'TH-A',
            'country_code' => 'MY', 'base_currency' => 'MYR', 'fiscal_year_start' => '01-01',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity B', 'code' => 'TH-B',
            'country_code' => 'MY', 'base_currency' => 'MYR', 'fiscal_year_start' => '01-01',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $date = now()->startOfMonth()->subMonthsNoOverflow(2)->addDays(5)->toDateString();
        $this->postedInvoice($date, 600000.00, null, $entityA);
        $this->postedInvoice($date, 700000.00, null, $entityB);

        $a = $this->actingAs($this->user)
            ->getJson("/api/v1/accounting/tax-engine/thresholds?jurisdiction=MY&entity_id={$entityA}");
        $a->assertOk()
            ->assertJsonPath('data.MY.thresholds.0.monitoring_flag', true);
        $this->assertSame(600000.0, (float) $a->json('data.MY.trailing_12m_taxable_value'));

        $b = $this->actingAs($this->user)
            ->getJson("/api/v1/accounting/tax-engine/thresholds?jurisdiction=MY&entity_id={$entityB}");
        $b->assertOk();
        $this->assertSame(700000.0, (float) $b->json('data.MY.trailing_12m_taxable_value'));

        $all = $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/tax-engine/thresholds?jurisdiction=MY');
        $all->assertOk();
        $this->assertSame(1300000.0, (float) $all->json('data.MY.trailing_12m_taxable_value'));
    }
}
