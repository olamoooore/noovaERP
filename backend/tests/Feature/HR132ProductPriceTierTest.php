<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;
use Modules\Sales\Services\SalesOrderService;
use Tests\TenantTestCase;

/**
 * Issue #132 (HIGH) — ProductPriceTier models volume/customer/date pricing but
 * no sale path consulted it, so every configured tier was inert. The price is
 * now resolved through Product::getPriceForQuantity() (which uses each tier's
 * isValidFor() and calculatePrice()).
 *
 * Note: the real product_price_tiers schema uses discount_type +
 * discount_value (the model previously advertised non-existent
 * price/discount_percent columns — corrected in the same change).
 */
class HR132ProductPriceTierTest extends TenantTestCase
{
    private Product $product;

    private Warehouse $warehouse;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = (string) Str::uuid();
        $this->bindTenantContext($tenantId);

        $this->assertTenantSchemaReady(['products', 'product_price_tiers', 'warehouses', 'sales_orders']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $tenantId,
            'name' => 'Price Tier Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->warehouse = Warehouse::create([
            'name' => 'Tier Warehouse',
            'code' => 'TIER-'.uniqid(),
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Tier Widget',
            'sku' => 'TIER-'.uniqid(),
            'track_inventory' => false,
            'price' => 10.00,
            'cost' => 5.00,
        ]);
    }

    private function tier(array $attributes = []): ProductPriceTier
    {
        return ProductPriceTier::create(array_merge([
            'product_id' => $this->product->id,
            'min_quantity' => 10,
            'discount_type' => ProductPriceTier::TYPE_FIXED_PRICE,
            'discount_value' => 5.00,
            'is_active' => true,
        ], $attributes));
    }

    private function orderLinePrice(int $quantity, ?int $customerId = null): float
    {
        $order = app(SalesOrderService::class)->createOrder([
            'customer_id' => $customerId ?? Customer::factory()->create()->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Tier line',
                'quantity' => $quantity,
                'tax_rate' => 0,
            ]],
        ]);

        return (float) $order->lines()->first()->unit_price;
    }

    // ─────────────────────────────────────────────────────────────────────
    // The issue's verification
    // ─────────────────────────────────────────────────────────────────────

    public function test_volume_tier_price_is_applied_to_sales_order(): void
    {
        $this->tier(['min_quantity' => 10, 'discount_type' => 'fixed_price', 'discount_value' => 5.00]);

        $this->assertSame(5.0, $this->orderLinePrice(10), '10+ units must use the tier price, not the base 10.00');
        $this->assertSame(5.0, $this->orderLinePrice(25), 'Quantity inside the break must use the tier price');
    }

    public function test_base_price_applies_below_the_tier_threshold(): void
    {
        $this->tier(['min_quantity' => 10, 'discount_type' => 'fixed_price', 'discount_value' => 5.00]);

        $this->assertSame(10.0, $this->orderLinePrice(9));
    }

    public function test_customer_specific_tier_wins_over_generic_tier(): void
    {
        $customer = Customer::factory()->create();

        $this->tier(['min_quantity' => 10, 'discount_type' => 'fixed_price', 'discount_value' => 5.00]);
        $this->tier([
            'customer_id' => $customer->id,
            'min_quantity' => 1,
            'discount_type' => 'fixed_price',
            'discount_value' => 3.00,
        ]);

        $this->assertSame(3.0, $this->orderLinePrice(1, $customer->id));
    }

    public function test_percentage_tier_is_applied(): void
    {
        $this->tier(['min_quantity' => 10, 'discount_type' => 'percentage', 'discount_value' => 10]);

        $this->assertSame(9.0, $this->orderLinePrice(12));
    }

    public function test_expired_tier_is_ignored(): void
    {
        $this->tier([
            'min_quantity' => 1,
            'discount_type' => 'fixed_price',
            'discount_value' => 5.00,
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        $this->assertSame(10.0, $this->orderLinePrice(10), 'An expired tier must not apply');
    }

    public function test_future_tier_is_ignored(): void
    {
        $this->tier([
            'min_quantity' => 1,
            'discount_type' => 'fixed_price',
            'discount_value' => 5.00,
            'valid_from' => now()->addDay()->toDateString(),
        ]);

        $this->assertSame(10.0, $this->orderLinePrice(10), 'A not-yet-valid tier must not apply');
    }

    public function test_best_applicable_tier_is_selected(): void
    {
        $this->tier(['min_quantity' => 1, 'discount_type' => 'fixed_price', 'discount_value' => 9.00]);
        $this->tier(['min_quantity' => 10, 'discount_type' => 'fixed_price', 'discount_value' => 5.00]);

        $this->assertSame(9.0, $this->orderLinePrice(1));
        $this->assertSame(5.0, $this->orderLinePrice(12));
    }

    public function test_inactive_tier_is_ignored(): void
    {
        $this->tier(['min_quantity' => 1, 'discount_type' => 'fixed_price', 'discount_value' => 5.00, 'is_active' => false]);

        $this->assertSame(10.0, $this->orderLinePrice(10));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Model-level canonical logic
    // ─────────────────────────────────────────────────────────────────────

    public function test_tier_calculation_types(): void
    {
        $this->assertSame(5.0, $this->tier(['discount_type' => 'fixed_price', 'discount_value' => 5])->calculatePrice(10.0));
        $this->assertSame(7.5, $this->tier(['discount_type' => 'fixed', 'discount_value' => 2.5])->calculatePrice(10.0));
        $this->assertSame(8.0, $this->tier(['discount_type' => 'percentage', 'discount_value' => 20])->calculatePrice(10.0));
    }

    public function test_expected_columns_exist_on_the_real_schema(): void
    {
        foreach (['discount_type', 'discount_value', 'valid_from', 'valid_until', 'customer_id', 'min_quantity', 'max_quantity'] as $column) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn('product_price_tiers', $column),
                "product_price_tiers.{$column} is required for tier pricing"
            );
        }
    }
}
