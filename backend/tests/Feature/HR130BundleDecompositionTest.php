<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BundleDecompositionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Sales\Services\DeliveryOrderService;
use Tests\TenantTestCase;

/**
 * Issue #130 (HIGH) — selling a bundle/kit never decomposed into its component
 * products: component stock never moved and bundle COGS was the bundle
 * product's own (usually unset) cost.
 *
 * Verification from the issue: sell a bundle with 2 known components, assert
 * both components' stock levels decrease by the correct quantity, and the
 * resulting COGS equals the sum of the components' costs.
 */
class HR130BundleDecompositionTest extends TenantTestCase
{
    private Product $bundle;

    private Product $componentA;

    private Product $componentB;

    private Warehouse $warehouse;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = (string) Str::uuid();
        $this->bindTenantContext($tenantId);

        $this->assertTenantSchemaReady(['products', 'product_bundles', 'warehouses', 'stock_levels', 'delivery_orders']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $tenantId,
            'name' => 'Bundle Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->warehouse = Warehouse::create([
            'name' => 'Bundle Warehouse',
            'code' => 'BND-'.uniqid(),
            'is_active' => true,
        ]);

        // Bundle is virtual (assembled on sale) — not stock-tracked itself.
        $this->bundle = Product::factory()->create([
            'name' => 'Gift Basket',
            'sku' => 'BND-'.uniqid(),
            'is_bundle' => true,
            'track_inventory' => false,
            'price' => 50,
            'cost' => 0,
        ]);

        $this->componentA = Product::factory()->create([
            'name' => 'Red Wine',
            'sku' => 'CMP-A-'.uniqid(),
            'track_inventory' => true,
            'price' => 20,
            'cost' => 5.00,
        ]);

        $this->componentB = Product::factory()->create([
            'name' => 'Chocolate',
            'sku' => 'CMP-B-'.uniqid(),
            'track_inventory' => true,
            'price' => 10,
            'cost' => 3.00,
        ]);

        // 1 Gift Basket = 2 Red Wine + 1 Chocolate.
        ProductBundle::create([
            'bundle_product_id' => $this->bundle->id,
            'component_product_id' => $this->componentA->id,
            'quantity' => 2,
        ]);
        ProductBundle::create([
            'bundle_product_id' => $this->bundle->id,
            'component_product_id' => $this->componentB->id,
            'quantity' => 1,
        ]);

        foreach ([$this->componentA, $this->componentB] as $component) {
            StockLevel::create([
                'product_id' => $component->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 10,
                'reserved_quantity' => 0,
                'min_quantity' => 0,
            ]);
        }
    }

    private function stockOf(Product $product): int
    {
        return (int) StockLevel::where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('quantity');
    }

    private function deliver(int $quantity): void
    {
        $service = app(DeliveryOrderService::class);

        $do = $service->createStandaloneDeliveryOrder([
            'customer_id' => Customer::factory()->create()->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => [[
                'product_id' => $this->bundle->id,
                'description' => 'Gift Basket',
                'quantity_delivered' => $quantity,
                'unit' => 'pcs',
            ]],
        ]);

        $service->completeDeliveryOrder($do);
    }

    // ─────────────────────────────────────────────────────────────────────
    // The issue's verification
    // ─────────────────────────────────────────────────────────────────────

    public function test_selling_a_bundle_decomposes_into_component_stock_movements(): void
    {
        $this->deliver(3); // 3 Gift Baskets = 6 Red Wine + 3 Chocolate

        $this->assertSame(4, $this->stockOf($this->componentA), 'Red Wine must drop by 2 x 3 = 6');
        $this->assertSame(7, $this->stockOf($this->componentB), 'Chocolate must drop by 1 x 3 = 3');

        // Stock movements were recorded against the components, not the bundle.
        $movementA = DB::table('stock_movements')->where('product_id', $this->componentA->id)->where('type', 'sale')->first();
        $movementB = DB::table('stock_movements')->where('product_id', $this->componentB->id)->where('type', 'sale')->first();

        $this->assertNotNull($movementA);
        $this->assertNotNull($movementB);
        $this->assertSame(6, (int) $movementA->quantity, 'Component A movement carries the decomposed 2 x 3 quantity');
        $this->assertSame(3, (int) $movementB->quantity, 'Component B movement carries the decomposed 1 x 3 quantity');
        $this->assertSame(0, DB::table('stock_movements')->where('product_id', $this->bundle->id)->count(), 'The virtual bundle itself must not move stock');
    }

    public function test_bundle_cogs_is_the_sum_of_component_costs(): void
    {
        $cost = app(BundleDecompositionService::class)->cost($this->bundle, 3);

        // (5.00 x 2 + 3.00 x 1) x 3 = 39.00 — not the bundle product's cost (0).
        $this->assertSame(39.0, $cost);
    }

    public function test_non_bundle_lines_are_unchanged(): void
    {
        $service = app(DeliveryOrderService::class);

        $do = $service->createStandaloneDeliveryOrder([
            'customer_id' => Customer::factory()->create()->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => [[
                'product_id' => $this->componentA->id,
                'description' => 'Red Wine',
                'quantity_delivered' => 2,
                'unit' => 'pcs',
            ]],
        ]);

        $service->completeDeliveryOrder($do);

        $this->assertSame(8, $this->stockOf($this->componentA));
        $this->assertSame(10, $this->stockOf($this->componentB));
        $this->assertSame(10.0, app(BundleDecompositionService::class)->cost($this->componentA, 2));
    }

    public function test_decomposition_expands_scaled_component_quantities(): void
    {
        $components = app(BundleDecompositionService::class)->stockConsumingComponents($this->bundle, 2.5);

        $byId = collect($components)->keyBy(fn ($entry) => $entry['product']->id);

        $this->assertSame(5.0, (float) $byId[$this->componentA->id]['quantity']);
        $this->assertSame(2.5, (float) $byId[$this->componentB->id]['quantity']);
    }

    public function test_bundle_discount_columns_match_the_real_schema(): void
    {
        foreach (['component_variant_id', 'discount_type', 'discount_value', 'quantity'] as $column) {
            $this->assertTrue(
                DB::getSchemaBuilder()->hasColumn('product_bundles', $column),
                "product_bundles.{$column} is required for bundle decomposition/pricing"
            );
        }
    }
}
