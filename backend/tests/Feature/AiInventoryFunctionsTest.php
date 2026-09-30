<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Support\Str;
use Modules\AI\Functions\InventoryFunctions;
use Modules\Inventory\Models\StockLevel;
use Tests\TenantTestCase;

/**
 * AI inventory functions must agree with the inventory dashboard: both
 * count from `stock_levels` (not the legacy products.stock_quantity), and
 * the AI must be able to report the FULL total even when listing a sample.
 */
class AiInventoryFunctionsTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContext((string) Str::uuid());

        // Product factory audit attribution needs a tenant user.
        if (! \App\Models\User::query()->exists()) {
            \App\Models\User::factory()->create();
        }
    }

    /** @test */
    public function out_of_stock_items_report_the_full_total_from_stock_levels(): void
    {
        $warehouse = Warehouse::factory()->create();

        // In stock: positive level.
        $inStock = Product::factory()->create(['name' => 'In Stock Item', 'is_active' => true, 'track_inventory' => true]);
        StockLevel::create(['product_id' => $inStock->id, 'warehouse_id' => $warehouse->id, 'quantity' => 25, 'min_quantity' => 5]);

        // Out of stock: zero level.
        $zero = Product::factory()->create(['name' => 'Zero Stock Item', 'is_active' => true, 'track_inventory' => true]);
        StockLevel::create(['product_id' => $zero->id, 'warehouse_id' => $warehouse->id, 'quantity' => 0, 'min_quantity' => 5]);

        // Out of stock: no stock level rows at all (dashboard semantics).
        Product::factory()->create(['name' => 'No Level Item', 'is_active' => true, 'track_inventory' => true]);

        $result = InventoryFunctions::listOutOfStock(['limit' => 1]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['total_count'], 'total_count must count every out-of-stock product');
        $this->assertCount(1, $result['items'], 'items is a sample limited by the requested limit');
    }

    /** @test */
    public function low_stock_items_follow_stock_level_minimums_with_fallback_threshold(): void
    {
        $warehouse = Warehouse::factory()->create();

        // Custom minimum: 5 <= 10 → low.
        $custom = Product::factory()->create(['name' => 'Custom Min', 'is_active' => true, 'track_inventory' => true]);
        StockLevel::create(['product_id' => $custom->id, 'warehouse_id' => $warehouse->id, 'quantity' => 5, 'min_quantity' => 10]);

        // Fallback threshold (min 0): 3 <= 10 → low.
        $fallback = Product::factory()->create(['name' => 'Fallback Min', 'is_active' => true, 'track_inventory' => true]);
        StockLevel::create(['product_id' => $fallback->id, 'warehouse_id' => $warehouse->id, 'quantity' => 3, 'min_quantity' => 0]);

        // Healthy: 50 > 10 → not low.
        $healthy = Product::factory()->create(['name' => 'Healthy', 'is_active' => true, 'track_inventory' => true]);
        StockLevel::create(['product_id' => $healthy->id, 'warehouse_id' => $warehouse->id, 'quantity' => 50, 'min_quantity' => 10]);

        $result = InventoryFunctions::listLowStock(['limit' => 10]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['total_count'], 'total_count must count every low-stock product');
        $this->assertCount(2, $result['items']);
    }
}
