<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #751 TU5: upcoming pack rate changes, with the rate in force today and the
 * open quotations/sales orders affected — computed on the server (the browser
 * never derives a tax rate). The pack mutation here is test-only.
 */
class UpcomingTaxRateChangeTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@ratechange.test']);
        Auth::login($this->user);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_upcoming_change_reports_the_rate_today_and_affected_open_documents(): void
    {
        $future = now()->addDays(10)->toDateString();

        // TEST-ONLY pack edit: a GST change takes effect in 10 days.
        config(['tax_packs.SG.rules' => array_merge((array) config('tax_packs.SG.rules'), [[
            'code' => 'TEST-FUTURE-GST', 'name' => 'Future GST', 'type' => 'gst', 'regime' => 'GST',
            'jurisdiction' => 'SG', 'scope' => 'both', 'treatment' => 'standard_rated',
            'rate' => 9.5, 'is_inclusive' => false, 'effective_from' => $future, 'effective_to' => null,
        ]])]);

        $customer = Customer::factory()->create();
        $before = now()->subDays(5)->toDateString();

        DB::table('quotations')->insert([
            ['quotation_number' => 'Q-OPEN-'.Str::random(6), 'customer_id' => $customer->id, 'quotation_date' => $before, 'status' => 'draft', 'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()],
            ['quotation_number' => 'Q-CANC-'.Str::random(6), 'customer_id' => $customer->id, 'quotation_date' => $before, 'status' => 'cancelled', 'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('sales_orders')->insert([
            ['sales_order_number' => 'SO-OPEN-'.Str::random(6), 'customer_id' => $customer->id, 'order_date' => $before, 'status' => 'confirmed', 'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()],
            ['sales_order_number' => 'SO-CANC-'.Str::random(6), 'customer_id' => $customer->id, 'order_date' => $before, 'status' => 'cancelled', 'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response = $this->getJson('/api/v1/accounting/tax-engine/upcoming-changes?days=30')->assertOk();

        $row = collect((array) $response->json('data'))->firstWhere('code', 'TEST-FUTURE-GST');
        $this->assertNotNull($row, 'The upcoming change must be reported.');
        $this->assertSame('SG', $row['jurisdiction']);
        $this->assertSame('gst', $row['type']);
        $this->assertSame(9.5, (float) $row['new_rate']);
        $this->assertSame(9.0, (float) $row['current_rate'], 'The rate in force today is the pack rate effective today.');
        $this->assertSame(10, (int) $row['days_until']);
        $this->assertSame(1, (int) $row['affected']['quotations'], 'Only open quotations count.');
        $this->assertSame(1, (int) $row['affected']['sales_orders'], 'Cancelled sales orders do not count.');
    }

    public function test_a_change_outside_the_window_is_not_reported(): void
    {
        config(['tax_packs.SG.rules' => array_merge((array) config('tax_packs.SG.rules'), [[
            'code' => 'TEST-FAR-GST', 'name' => 'Far GST', 'type' => 'gst', 'regime' => 'GST',
            'jurisdiction' => 'SG', 'scope' => 'both', 'treatment' => 'standard_rated',
            'rate' => 9.5, 'is_inclusive' => false, 'effective_from' => now()->addDays(90)->toDateString(), 'effective_to' => null,
        ]])]);

        $response = $this->getJson('/api/v1/accounting/tax-engine/upcoming-changes?days=30')->assertOk();

        $this->assertNull(collect((array) $response->json('data'))->firstWhere('code', 'TEST-FAR-GST'));
    }
}
