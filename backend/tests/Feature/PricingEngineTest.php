<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\CustomerPriceList;
use App\Models\CustomerPriceListItem;
use App\Models\ExchangeRate;
use App\Models\PriceList;
use App\Models\PriceListApplication;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Services\PricingService;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * MRD pricelist-multicurrency-mrd — PricingService engine suite.
 *
 * Covers AC-1 (pricelist pricing + chip + trace), AC-2 (currency bridge),
 * AC-6 (legacy migration equivalence), AC-8 (rounding determinism) plus the
 * normative FX rules (§9): first-match ordering, qty tiers, validity windows,
 * category scope, cost/other-pricelist bases and the manual floor guard.
 *
 * Tenant base = SGD. The engine is exercised directly (it must be
 * side-effect free — FR-4.5), not only through the router.
 */
class PricingEngineTest extends TenantTestCase
{
    private PricingService $pricing;

    /**
     * The engine's list/rate memo is REQUEST-scoped (FR-13). Tests mutate
     * price data between resolutions, so each resolution gets a fresh
     * instance — mirroring one HTTP request per document.
     */
    private function fresh(): PricingService
    {
        return app(PricingService::class);
    }

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => 'a1b2c3d4-0102-0304-0506-0708090a0b0c',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'price_lists', 'price_list_items', 'price_list_applications',
            'customer_price_lists', 'exchange_rates', 'currencies', 'company_settings',
        ]);

        CompanySettings::query()->create(['base_currency' => 'SGD']);
        // Product model observers require a tenant-schema user for audit
        // attribution (TenantUserHelper::requireTenantUserId).
        \App\Models\User::factory()->create();
        $this->pricing = app(PricingService::class);
        $this->customer = Customer::factory()->create();
    }

    private function product(float $price = 100.0, float $cost = 40.0, ?int $categoryId = null): Product
    {
        return Product::factory()->create([
            'price' => $price,
            'cost' => $cost,
            'category_id' => $categoryId,
            'track_inventory' => false,
        ]);
    }

    private function list(string $currency = 'SGD', array $header = []): PriceList
    {
        return PriceList::create(array_merge([
            'name' => 'Test list',
            'code' => 'TEST-'.uniqid(),
            'currency_code' => $currency,
            'sequence' => 10,
            'is_active' => true,
        ], $header));
    }

    private function item(PriceList $list, array $attrs): PriceListItem
    {
        return $list->items()->create($attrs);
    }

    private function appliesTo(PriceList $list, Customer $customer): void
    {
        PriceListApplication::create([
            'price_list_id' => $list->id,
            'applies_to_type' => 'customer',
            'applies_to_id' => $customer->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // AC-8 — rounding determinism
    // ─────────────────────────────────────────────────────────────────────

    public function test_ac8_nearest_rounding_precision_005_is_deterministic(): void
    {
        $product = $this->product(price: 100.00);
        $list = $this->list('SGD', [
            'rounding_enabled' => true,
            'rounding_method' => 'nearest',
            'rounding_precision' => 0.05,
        ]);
        $this->appliesTo($list, $this->customer);

        // percent such that the raw formula result is 12.33 → rounds to 12.35
        $this->item($list, [
            'sequence' => 10,
            'apply_on' => 'product',
            'product_id' => $product->id,
            'min_quantity' => 1,
            'compute_type' => 'formula',
            'base' => 'list_price',
            // 100 × (1 - 87.67%) = 12.33
            'percent' => 87.67,
        ]);

        $quote = $this->fresh()->resolvePrice([
            'product' => $product,
            'quantity' => 7,
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
        ]);

        $this->assertSame(12.35, round((float) $quote['unit_price'], 2), 'Rounded unit price must be 12.35 (nearest 0.05 of 12.33).');

        // Line total = qty × rounded unit (FR-3.7: rounding BEFORE multiplication)
        $this->assertSame(86.45, round(7 * (float) $quote['unit_price'], 2));

        // Byte-equal determinism across runs (FR §9.7)
        $again = $this->fresh()->resolvePrice([
            'product' => $product,
            'quantity' => 7,
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
        ]);
        $this->assertSame(json_encode($quote['price_source']), json_encode($again['price_source']), 'Identical inputs must produce identical traces.');
    }

    public function test_rounding_up_and_down_methods(): void
    {
        $product = $this->product(price: 100.00);

        foreach ([['up', 12.35], ['down', 12.30]] as [$method, $expected]) {
            $list = $this->list('SGD', [
                'code' => 'ROUND-'.strtoupper($method),
                'rounding_enabled' => true,
                'rounding_method' => $method,
                'rounding_precision' => 0.05,
            ]);
            $this->appliesTo($list, $this->customer);
            $this->item($list, [
                'sequence' => 10,
                'apply_on' => 'product',
                'product_id' => $product->id,
                'compute_type' => 'formula',
                'base' => 'list_price',
                'percent' => 87.67, // → 12.33
            ]);

            $quote = $this->fresh()->resolvePrice([
                'product' => $product,
                'quantity' => 1,
                'customer_id' => $this->customer->id,
                'date' => now()->toDateString(),
            ]);

            $this->assertSame($expected, round((float) $quote['unit_price'], 2), "rounding {$method}");

            // Both test lists share sequence 10 — the id tie-break would let
            // the 'up' list win again on the next iteration otherwise.
            $list->update(['is_active' => false]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // AC-1 — pricelist rule, trace provenance, qty tiers, first-match
    // ─────────────────────────────────────────────────────────────────────

    public function test_ac1_category_min_qty_discount_rule_with_trace(): void
    {
        $category = ProductCategory::create(['name' => 'Consumables']);
        $product = $this->product(price: 50.00, categoryId: $category->id);

        $list = $this->list('SGD', ['name' => 'Export SG', 'code' => 'EXPORT-SG']);
        $this->appliesTo($list, $this->customer);

        // A higher-sequence generic rule must NOT win over the first match (FR-3.5)
        $this->item($list, ['sequence' => 5, 'apply_on' => 'all', 'compute_type' => 'fixed', 'fixed_price' => 999]);
        $promo = $this->item($list, [
            'sequence' => 10,
            'apply_on' => 'category',
            'category_id' => $category->id,
            'min_quantity' => 5,
            'compute_type' => 'percentage',
            'percent' => 10,
        ]);

        // qty 4 → the catch-all (sequence 5) matches FIRST — top-down,
        // first match wins even if the promo could apply at qty 5.
        $belowTier = $this->fresh()->resolvePrice([
            'product' => $product,
            'quantity' => 4,
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
        ]);
        $this->assertSame(999.0, round((float) $belowTier['unit_price'], 2));

        // list1 (id tie-break at equal sequence) would win over list2 — it is
        // done, deactivate it so list2 resolves (FR-2.5).
        $list->update(['is_active' => false]);

        // Reordered list: promo precedes the catch-all, and at qty 5 the
        // promo (category scope + min-qty tier) matches first.
        $list2 = $this->list('SGD', ['name' => 'Q4 promo', 'code' => 'Q4']);
        $this->appliesTo($list2, $this->customer);
        $promo2 = $this->item($list2, [
            'sequence' => 10,
            'apply_on' => 'category',
            'category_id' => $category->id,
            'min_quantity' => 5,
            'compute_type' => 'percentage',
            'percent' => 10,
        ]);
        $this->item($list2, ['sequence' => 20, 'apply_on' => 'all', 'compute_type' => 'fixed', 'fixed_price' => 999]);

        $quote = $this->fresh()->resolvePrice([
            'product' => $product,
            'quantity' => 5,
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
        ]);

        $this->assertSame(45.0, round((float) $quote['unit_price'], 2), '5 × category −10% → 45.00');
        $this->assertSame('pricelist', $quote['source']);
        $this->assertSame($promo2->id, $quote['price_source']['item_id']);
        $this->assertSame($list2->id, $quote['price_source']['pricelist_id']);
        $this->assertSame('Q4', $quote['price_source']['pricelist_code']);
        $this->assertSame('percentage', $quote['price_source']['compute_type']);
        $this->assertSame(50.0, (float) $quote['price_source']['base_value'], 'trace names the base value');
        $this->assertSame(10.0, (float) $quote['price_source']['percent']);
    }

    public function test_percentage_negative_is_markup(): void
    {
        $product = $this->product(price: 100.00);
        $list = $this->list();
        $this->appliesTo($list, $this->customer);
        $this->item($list, [
            'sequence' => 10,
            'apply_on' => 'all',
            'compute_type' => 'percentage',
            'percent' => -5, // negative = markup (§8.1)
        ]);

        $quote = $this->fresh()->resolvePrice([
            'product' => $product,
            'quantity' => 1,
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
        ]);

        $this->assertSame(105.0, round((float) $quote['unit_price'], 2));
    }

    public function test_min_max_quantity_tiers_and_date_windows(): void
    {
        $product = $this->product(price: 100.00);
        $list = $this->list();
        $this->appliesTo($list, $this->customer);

        $this->item($list, [
            'sequence' => 10, 'apply_on' => 'all', 'min_quantity' => 10, 'max_quantity' => 19,
            'compute_type' => 'percentage', 'percent' => 15,
        ]);
        $this->item($list, [
            'sequence' => 20, 'apply_on' => 'all', 'min_quantity' => 20,
            'date_start' => '2026-01-01', 'date_end' => '2026-01-31',
            'compute_type' => 'percentage', 'percent' => 25,
        ]);

        $inBand = $this->fresh()->resolvePrice([
            'product' => $product, 'quantity' => 12,
            'customer_id' => $this->customer->id, 'date' => '2026-01-15',
        ]);
        $this->assertSame(85.0, round((float) $inBand['unit_price'], 2), '10-19 band → −15%');

        $outOfBand = $this->fresh()->resolvePrice([
            'product' => $product, 'quantity' => 25,
            'customer_id' => $this->customer->id, 'date' => '2026-01-15',
        ]);
        $this->assertSame(75.0, round((float) $outOfBand['unit_price'], 2), '25 fails max_quantity 19 → next rule (min 20, Jan window) matches → −25%');

        $expiredWindow = $this->fresh()->resolvePrice([
            'product' => $product, 'quantity' => 12,
            'customer_id' => $this->customer->id, 'date' => '2026-03-01',
        ]);
        $this->assertSame(85.0, round((float) $expiredWindow['unit_price'], 2), 'date-window rule skipped outside validity; the −15% band rule (10..19) still matches');
    }

    public function test_cost_based_and_other_pricelist_formula_bases(): void
    {
        $product = $this->product(price: 100.00, cost: 40.00);

        $costList = $this->list('SGD', ['code' => 'COST-PLUS']);
        $this->appliesTo($costList, $this->customer);
        $this->item($costList, [
            'sequence' => 10, 'apply_on' => 'all',
            'compute_type' => 'formula', 'base' => 'cost', 'markup_percent' => 20,
        ]);

        $quote = $this->fresh()->resolvePrice([
            'product' => $product, 'quantity' => 1,
            'customer_id' => $this->customer->id, 'date' => now()->toDateString(),
            'include_cost' => true,
        ]);
        $this->assertSame(48.0, round((float) $quote['unit_price'], 2), 'cost 40 + 20% markup');
        $this->assertSame('cost', $quote['price_source']['base']);
        $this->assertSame(40.0, (float) $quote['price_source']['base_value']);

        // Without cost capability the base VALUE is masked (FR-3.6)
        $masked = $this->fresh()->resolvePrice([
            'product' => $product, 'quantity' => 1,
            'customer_id' => $this->customer->id, 'date' => now()->toDateString(),
            'include_cost' => false,
        ]);
        $this->assertSame(48.0, round((float) $masked['unit_price'], 2), 'price still computed');
        $this->assertNull($masked['price_source']['base_value'], 'cost input hidden from non-cost roles');

        // other_pricelist base: list B bases on list A
        $listA = $this->list('SGD', ['code' => 'BASE-A']);
        $this->appliesTo($listA, $this->customer);
        $this->item($listA, ['sequence' => 10, 'apply_on' => 'all', 'compute_type' => 'percentage', 'percent' => 10]);

        $listB = $this->list('SGD', ['code' => 'BASE-B']);
        $this->appliesTo($listB, $this->customer);
        $this->item($listB, [
            'sequence' => 10, 'apply_on' => 'all',
            'compute_type' => 'formula', 'base' => 'other_pricelist',
            'other_price_list_id' => $listA->id, 'markup_percent' => 5,
        ]);

        $chained = $this->fresh()->resolvePrice([
            'product' => $product, 'quantity' => 1,
            'customer_id' => $this->customer->id, 'date' => now()->toDateString(),
            'price_list_id' => $listB->id,
        ]);
        $this->assertSame(94.5, round((float) $chained['unit_price'], 2), '(100 −10%) +5% = 94.50');
    }

    // ─────────────────────────────────────────────────────────────────────
    // AC-2 — currency bridge (list currency ≠ document currency)
    // ─────────────────────────────────────────────────────────────────────

    public function test_ac2_myr_list_bridged_into_sgd_document_with_snapshot(): void
    {
        ExchangeRate::create([
            'from_currency' => 'MYR', 'to_currency' => 'SGD', 'rate' => 0.3012,
            'effective_date' => '2026-09-29', 'source' => 'api',
        ]);

        $product = $this->product(price: 100.00);
        $list = $this->list('MYR', ['code' => 'MYR-LIST']);
        $this->appliesTo($list, $this->customer);
        $this->item($list, ['sequence' => 10, 'apply_on' => 'all', 'compute_type' => 'fixed', 'fixed_price' => 100]);

        // Document in SGD (the tenant base) → 100 MYR × 0.3012 = 30.12 SGD
        $quote = $this->fresh()->resolvePrice([
            'product' => $product,
            'quantity' => 3,
            'customer_id' => $this->customer->id,
            'document_currency' => 'SGD',
            'base_currency' => 'SGD',
            'date' => '2026-09-30',
        ]);

        $this->assertSame(30.12, round((float) $quote['unit_price'], 2));
        $this->assertSame('SGD', $quote['currency']);
        $this->assertSame(30.12, round((float) $quote['base_unit_price'], 2), 'document == base ⇒ base equals unit');
        $this->assertSame(1.0, (float) $quote['exchange_rate']);

        // The bridge rate used is snapshotted in the trace (FR-3.9/FR-4.3)
        $this->assertSame(0.3012, round((float) $quote['price_source']['rate_used'], 4), 'trace records the MYR→SGD bridge rate');
        $this->assertSame('2026-09-29', $quote['price_source']['rate_date']);
    }

    public function test_bridge_rounding_order_and_minor_units(): void
    {
        // A coarser list rounding must re-apply AFTER conversion (FR-4.3)
        ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'SGD', 'rate' => 1.3527,
            'effective_date' => now()->toDateString(), 'source' => 'api',
        ]);

        $product = $this->product();
        $list = $this->list('USD', [
            'code' => 'USD-05',
            'rounding_enabled' => true,
            'rounding_method' => 'nearest',
            'rounding_precision' => 0.05,
        ]);
        $this->appliesTo($list, $this->customer);
        $this->item($list, ['sequence' => 10, 'apply_on' => 'all', 'compute_type' => 'fixed', 'fixed_price' => 7.3]);

        $quote = $this->fresh()->resolvePrice([
            'product' => $product, 'quantity' => 1,
            'customer_id' => $this->customer->id,
            'document_currency' => 'SGD', 'base_currency' => 'SGD',
            'date' => now()->toDateString(),
        ]);

        // 7.30 USD → 9.8747 SGD → minor-unit 9.87 → list rounding 0.05 is
        // COARSER (0.05 > 0.01) so it re-applies → 9.85
        $this->assertSame(9.85, round((float) $quote['unit_price'], 2));
    }

    // ─────────────────────────────────────────────────────────────────────
    // FR-4.2 resolution order + manual override floor (FR-6.3)
    // ─────────────────────────────────────────────────────────────────────

    public function test_no_pricelist_falls_back_to_tier_catalog_price(): void
    {
        $product = $this->product(price: 100.00);

        $quote = $this->fresh()->resolvePrice([
            'product' => $product,
            'quantity' => 2,
            'customer_id' => $this->customer->id,
            'date' => now()->toDateString(),
        ]);

        $this->assertSame(100.0, round((float) $quote['unit_price'], 2));
        $this->assertSame('catalog', $quote['source']);
    }

    public function test_manual_override_records_provenance_and_floor_requires_reason(): void
    {
        $product = $this->product(price: 100.00);

        // Within the 10% floor: allowed without a reason
        $ok = $this->fresh()->resolvePrice([
            'product' => $product, 'quantity' => 1,
            'customer_id' => null, 'date' => now()->toDateString(),
            'manual_price' => 92.00,
        ]);
        $this->assertSame(92.0, round((float) $ok['unit_price'], 2));
        $this->assertSame('manual', $ok['source']);
        $this->assertSame(100.0, (float) $ok['price_source']['resolved_unit_price'], 'trace keeps the engine-resolved reference price');

        // Below the floor (more than 10% under) without reason → refused (FR-6.3)
        $this->expectException(\App\Exceptions\PricingException::class);
        $this->expectExceptionMessage('override_reason');

        $this->fresh()->resolvePrice([
            'product' => $product, 'quantity' => 1,
            'customer_id' => null, 'date' => now()->toDateString(),
            'manual_price' => 80.00,
        ]);
    }

    public function test_below_floor_override_with_reason_is_accepted_and_audited(): void
    {
        $product = $this->product(price: 100.00);

        $quote = $this->fresh()->resolvePrice([
            'product' => $product, 'quantity' => 1,
            'customer_id' => null, 'date' => now()->toDateString(),
            'manual_price' => 80.00,
            'override_reason' => 'customer contract price agreed in writing',
        ]);

        $this->assertSame(80.0, round((float) $quote['unit_price'], 2));
        $this->assertTrue((bool) $quote['price_source']['override_below_floor']);
        $this->assertSame('customer contract price agreed in writing', $quote['price_source']['override_reason']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // FR-2.3 — applicability + priority ordering
    // ─────────────────────────────────────────────────────────────────────

    public function test_specificity_order_explicit_then_customer_then_group_then_global(): void
    {
        $global = $this->list('SGD', ['name' => 'Global', 'code' => 'G', 'sequence' => 1]);
        PriceListApplication::create(['price_list_id' => $global->id, 'applies_to_type' => 'all', 'applies_to_id' => null]);
        $this->item($global, ['sequence' => 10, 'apply_on' => 'all', 'compute_type' => 'fixed', 'fixed_price' => 300]);

        $group = $this->list('SGD', ['name' => 'Group', 'code' => 'GR', 'sequence' => 2]);
        $groupId = \App\Models\CustomerCategory::create(['name' => 'Wholesale buyers'])->id;
        $this->customer->categories()->attach($groupId);
        PriceListApplication::create(['price_list_id' => $group->id, 'applies_to_type' => 'customer_group', 'applies_to_id' => $groupId]);
        $this->item($group, ['sequence' => 10, 'apply_on' => 'all', 'compute_type' => 'fixed', 'fixed_price' => 200]);

        $personal = $this->list('SGD', ['name' => 'Contract', 'code' => 'P', 'sequence' => 3]);
        $this->appliesTo($personal, $this->customer);
        $this->item($personal, ['sequence' => 10, 'apply_on' => 'all', 'compute_type' => 'fixed', 'fixed_price' => 100]);

        $product = $this->product();

        // Customer-specific beats group beats global even with a worse sequence
        $quote = $this->fresh()->resolvePrice([
            'product' => $product, 'quantity' => 1,
            'customer_id' => $this->customer->id, 'date' => now()->toDateString(),
        ]);
        $this->assertSame(100.0, round((float) $quote['unit_price'], 2));

        // Explicit selection wins over everything (FR-2.3 step 1)
        $explicit = $this->fresh()->resolvePrice([
            'product' => $product, 'quantity' => 1,
            'customer_id' => $this->customer->id, 'date' => now()->toDateString(),
            'price_list_id' => $global->id,
        ]);
        $this->assertSame(300.0, round((float) $explicit['unit_price'], 2));

        // Deactivation removes the list from resolution (FR-2.5)
        $personal->update(['is_active' => false]);
        $afterDeactivate = $this->fresh()->resolvePrice([
            'product' => $product, 'quantity' => 1,
            'customer_id' => $this->customer->id, 'date' => now()->toDateString(),
        ]);
        $this->assertSame(200.0, round((float) $afterDeactivate['unit_price'], 2), 'group list now wins after the contract list is deactivated');
    }

    public function test_expired_price_list_is_not_resolved(): void
    {
        $list = $this->list('SGD', ['valid_until' => now()->subDay()->toDateString()]);
        $this->appliesTo($list, $this->customer);
        $this->item($list, ['sequence' => 10, 'apply_on' => 'all', 'compute_type' => 'fixed', 'fixed_price' => 50]);

        $product = $this->product(price: 100.0);
        $quote = $this->fresh()->resolvePrice([
            'product' => $product, 'quantity' => 1,
            'customer_id' => $this->customer->id, 'date' => now()->toDateString(),
        ]);
        $this->assertSame(100.0, round((float) $quote['unit_price'], 2), 'expired list ignored → catalogue');
    }

    // ─────────────────────────────────────────────────────────────────────
    // AC-6 — legacy migration equivalence (data bridge, engine parity)
    // ─────────────────────────────────────────────────────────────────────

    public function test_ac6_migrated_legacy_list_reproduces_legacy_prices(): void
    {
        // Production state this simulates: customers.price_list_id FK points
        // at customer_price_lists. The fresh test schema already carries the
        // POST-swap FK, so drop it first to model the pre-migration world.
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_price_list_id_price_lists_foreign');
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_price_list_id_foreign');

        $legacy = CustomerPriceList::create([
            'name' => 'Acme contract',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'is_active' => true,
        ]);
        $fixedProduct = $this->product(price: 100.00);
        $otherProduct = $this->product(price: 77.00);
        CustomerPriceListItem::create([
            'price_list_id' => $legacy->id,
            'product_id' => $otherProduct->id,
            'price' => 66.00,
            'min_quantity' => 1,
        ]);

        $customer = Customer::factory()->create();
        DB::table('customers')->where('id', $customer->id)->update(['price_list_id' => $legacy->id]);

        // BEFORE: legacy engine prices (the dead-but-defined getProductPrice
        // arithmetic AC-6 pins):
        $beforeFixed = 66.00;
        $beforeBlanket = round(100.00 * (1 - 10 / 100), 2);

        // Run the real migration pair.
        $m3 = require base_path('database/migrations/tenant/2026_10_01_000003_migrate_legacy_customer_price_lists.php');
        $m3->up();

        $newId = PriceList::query()->where('legacy_price_list_id', $legacy->id)->value('id');
        $this->assertNotNull($newId, 'legacy list must map into price_lists');

        $m6 = require base_path('database/migrations/tenant/2026_10_01_000006_repoint_customer_price_list_fk.php');
        $m6->up();

        $assigned = (int) DB::table('customers')->where('id', $customer->id)->value('price_list_id');
        $this->assertSame((int) $newId, $assigned, 'customer FK re-pointed onto the new list (FR-12.3)');
        $this->assertSame((int) $legacy->id, (int) DB::table('customers')->where('id', $customer->id)->value('legacy_price_list_id'));

        $list = PriceList::find($newId);
        $this->assertSame('SGD', $list->currency_code, 'migrated list uses the tenant base currency');
        $this->assertSame(2, $list->items()->count(), 'one fixed item + one blanket percentage rule');

        // AFTER: engine prices must match legacy exactly (AC-6 regression).
        $afterFixed = $this->fresh()->resolvePrice([
            'product' => $otherProduct, 'quantity' => 1,
            'customer_id' => $customer->id, 'date' => now()->toDateString(),
        ]);
        $afterBlanket = $this->fresh()->resolvePrice([
            'product' => $fixedProduct, 'quantity' => 1,
            'customer_id' => $customer->id, 'date' => now()->toDateString(),
        ]);

        $this->assertSame($beforeFixed, round((float) $afterFixed['unit_price'], 2), 'fixed item price unchanged');
        $this->assertSame($beforeBlanket, round((float) $afterBlanket['unit_price'], 2), 'blanket discount price unchanged');
        $this->assertSame('pricelist', $afterFixed['source']);

        // Rollback cleanliness (guarded, reversible — FR-12.3)
        $m6->down();
        $this->assertSame(
            (int) $legacy->id,
            (int) DB::table('customers')->where('id', $customer->id)->value('price_list_id'),
            'down() restores the legacy assignment'
        );
        $m3->down();
        $this->assertSame(0, PriceList::query()->whereNotNull('legacy_price_list_id')->count(), 'down() removes migrated rows');
    }

    // ─────────────────────────────────────────────────────────────────────
    // FR-4.6 — batch determinism + perf shape
    // ─────────────────────────────────────────────────────────────────────

    public function test_resolve_batch_matches_single_resolution(): void
    {
        $products = collect(range(1, 5))->map(fn () => $this->product(price: random_int(20, 200) * 1.0));
        $lines = $products->map(fn (Product $p) => ['product' => $p, 'quantity' => 3])->all();

        $batch = $this->pricing->resolveBatch($lines, [
            'customer_id' => null,
            'date' => now()->toDateString(),
        ]);

        foreach ($batch as $i => $quote) {
            $single = $this->fresh()->resolvePrice([
                'product' => $products[$i],
                'quantity' => 3,
                'date' => now()->toDateString(),
            ]);
            $this->assertSame((float) $single['unit_price'], (float) $quote['unit_price'], "batch line {$i}");
        }
    }
}
