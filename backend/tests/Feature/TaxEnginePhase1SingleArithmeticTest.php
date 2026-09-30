<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TaxEngineService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TenantTestCase;

/**
 * MRD Phase 1 acceptance:
 *  - R3.1: every document line computes through TaxEngineService::calculate().
 *  - R1.2: editing a LIVE rule versioning-protects history; the engine then
 *          derives different correct rates for pre/post change dates from the
 *          same code path (the "2025-06 doc vs 2026-07 doc" DoD proof).
 */
class TaxEnginePhase1SingleArithmeticTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        $user = $this->user;
        $user->assignRole('Admin');
        \Illuminate\Support\Facades\DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Auth::login($user);
    }

    public static function amountMatrix(): array
    {
        return [
            'plain exclusive' => [100.0, 9.0, false, 0.0],
            'exclusive + discount' => [500.0, 8.0, false, 100.0],
            'inclusive extract' => [109.0, 9.0, true, 0.0],
            'inclusive + discount' => [1000.0, 10.0, true, 37.5],
            'zero rate' => [250.0, 0.0, false, 0.0],
            'odd cents' => [33.33, 9.0, false, 3.33],
        ];
    }

    /**
     * @dataProvider amountMatrix
     */
    public function test_invoice_line_matches_engine_exactly(float $amount, float $rate, bool $inclusive, float $discount): void
    {
        $line = new InvoiceLine([
            'quantity' => 1,
            'unit_price' => $amount,
            'tax_rate' => $rate,
            'tax_inclusive' => $inclusive,
            'discount_amount' => $discount,
        ]);
        $line->calculateTotals();

        $calc = TaxEngineService::calculate($amount, $rate, $inclusive, $discount);

        $this->assertSame($calc['tax'], (float) $line->tax_amount, 'InvoiceLine tax must equal engine output');
        $this->assertSame($calc['gross'], (float) $line->line_total, 'InvoiceLine total must equal engine output');
    }

    /**
     * @dataProvider amountMatrix
     */
    public function test_quotation_and_sales_order_lines_match_engine(float $amount, float $rate, bool $inclusive, float $discount): void
    {
        $quotation = Quotation::create([
            'customer_id' => Customer::create(['name' => 'P1', 'email' => 'p1@x.test', 'is_active' => true])->id,
            'quotation_date' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => Auth::id(),
        ]);
        $qLine = new QuotationLine([
            'quotation_id' => $quotation->id,
            'quantity' => 1,
            'unit_price' => $amount,
            'tax_rate' => $rate,
            'discount_amount' => $discount,
        ]);
        $qLine->tax_inclusive = $inclusive;
        $qLine->calculateLineTotals();

        $calc = TaxEngineService::calculate($amount, $rate, $inclusive, $discount);
        $this->assertSame($calc['tax'], (float) $qLine->tax_amount);
        $this->assertSame($calc['gross'], (float) $qLine->line_total);

        $order = SalesOrder::create([
            'customer_id' => $quotation->customer_id,
            'order_date' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => Auth::id(),
        ]);
        $soLine = new SalesOrderLine([
            'sales_order_id' => $order->id,
            'quantity' => 1,
            'unit_price' => $amount,
            'tax_rate' => $rate,
            'discount_amount' => $discount,
        ]);
        $soLine->tax_inclusive = $inclusive;
        $soLine->calculateLineTotal();

        $this->assertSame($calc['tax'], (float) $soLine->tax_amount);
        $this->assertSame($calc['gross'], (float) $soLine->line_total);
    }

    public function test_editing_a_live_rule_versions_it_and_past_dates_keep_the_old_rate(): void
    {
        $rule = Tax::create([
            'name' => 'SG GST Output',
            'code' => 'SG-GST-9',
            'type' => 'gst',
            'regime' => 'GST',
            'jurisdiction' => 'SG',
            'scope' => 'output',
            'treatment' => 'standard_rated',
            'rate' => 8,
            'is_active' => true,
            'effective_from' => now()->subMonths(3)->toDateString(),
        ]);

        $before = now()->subMonth()->toDateString();
        $after = now()->addMonth()->toDateString();

        $response = $this->actingAs($this->user)
            ->putJson('/api/v1/taxes/'.$rule->id, [
                'rate' => 9,
                'effective_from' => now()->toDateString(),
            ]);

        $response->assertOk()
            ->assertJsonPath('versioned', true);
        $this->assertSame(9.0, (float) $response->json('rate'));

        $newId = (int) $response->json('id');
        $this->assertNotSame($rule->id, $newId, 'MRD R1.2: a live rule must be superseded by a new version row, not edited in place.');

        $old = Tax::query()->findOrFail($rule->id);
        $this->assertSame(8, (int) $old->rate, 'History row keeps the old rate.');
        $this->assertNotNull($old->effective_to);
        $this->assertSame(now()->subDay()->toDateString(), $old->effective_to->toDateString());

        $product = Product::create(['name' => 'P1 Prod', 'sku' => 'P1-'.Str::random(6), 'is_active' => true]);
        $engine = app(TaxEngineService::class);

        $past = $engine->resolve($product, 'SG', 'output', null, $before);
        $future = $engine->resolve($product, 'SG', 'output', null, $after);

        $this->assertSame(8.0, (float) $past['rate'], 'Pre-change documents must resolve the rate effective on their date.');
        $this->assertSame(9.0, (float) $future['rate'], 'Post-change documents must resolve the new rate — same code path.');
    }

    public function test_non_identity_edit_of_live_rule_stays_in_place(): void
    {
        $rule = Tax::create([
            'name' => 'Old Name',
            'code' => 'MY-SVC-8',
            'type' => 'service',
            'regime' => 'SST',
            'jurisdiction' => 'MY',
            'scope' => 'output',
            'treatment' => 'standard_rated',
            'rate' => 8,
            'is_active' => true,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);

        $response = $this->actingAs($this->user)
            ->putJson('/api/v1/taxes/'.$rule->id, ['name' => 'Renamed Only']);

        $response->assertOk()->assertJsonMissingPath('versioned');
        $this->assertSame($rule->id, (int) $response->json('id'));
        $this->assertSame('Renamed Only', Tax::query()->findOrFail($rule->id)->name);
    }
}
