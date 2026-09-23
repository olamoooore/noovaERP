<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\PurchaseOrderItem;
use Modules\Inventory\Models\Supplier;
use Modules\Sales\Services\SalesOrderService;
use Tests\TenantTestCase;

/**
 * Issue #135 (HIGH) — ProductUom conversion existed but no sale/purchase path
 * consulted it: a UOM-scaled quantity would be written to stock as base units.
 * The backend is now authoritative: a supplied `uom_id` is converted to base
 * units before it reaches stock, and pricing uses the canonical UOM rate.
 */
class HR135ProductUomConversionTest extends TenantTestCase
{
    private Product $product;

    private ProductUom $boxUom;

    private Warehouse $warehouse;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = (string) Str::uuid();
        $this->bindTenantContext($tenantId);

        $this->assertTenantSchemaReady(['products', 'product_uoms', 'warehouses', 'sales_orders', 'sales_order_lines']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $tenantId,
            'name' => 'UOM Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        // Tenant user first (ProductAuditLog needs audit attribution), authenticated.
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->warehouse = Warehouse::create([
            'name' => 'UOM Warehouse',
            'code' => 'UOM-'.uniqid(),
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'UOM Widget',
            'sku' => 'UOM-'.uniqid(),
            'track_inventory' => false,
            'price' => 10.00,
            'cost' => 5.00,
        ]);

        $this->boxUom = ProductUom::create([
            'product_id' => $this->product->id,
            'name' => 'Box',
            'abbreviation' => 'Box',
            'conversion_factor' => 12,
            'price_multiplier' => 1,
            'is_purchase_unit' => true,
            'is_sales_unit' => true,
            'is_active' => true,
        ]);
    }

    private function orderWithUom(int $uomId, float $quantity = 1): \Modules\Sales\Models\SalesOrder
    {
        return app(SalesOrderService::class)->createOrder([
            'customer_id' => Customer::factory()->create()->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'UOM line',
                'quantity' => $quantity,
                'uom_id' => $uomId,
                'tax_rate' => 0,
            ]],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sales
    // ─────────────────────────────────────────────────────────────────────

    public function test_sales_line_in_non_base_uom_is_converted_to_base_units(): void
    {
        $order = $this->orderWithUom($this->boxUom->id, 1);

        $line = $order->lines()->first();

        $this->assertSame(12.0, (float) $line->quantity, '1 Box must become 12 base units');
        // Issue #247: quantity is persisted in BASE units, so the label must
        // be the base unit ('pcs'), not the entered UOM ('12 Box' was nonsense).
        $this->assertSame('pcs', $line->unit);
        // Box price of a 10.00 product with factor 12 and multiplier 1 = 120.00;
        // stored as per-base price 10.00 so quantity × price = 120.00.
        $this->assertSame(10.0, (float) $line->unit_price);
        $this->assertSame(120.0, (float) $order->fresh()->subtotal);
    }

    public function test_explicit_unit_price_is_preserved_and_quantity_still_converted(): void
    {
        $order = app(SalesOrderService::class)->createOrder([
            'customer_id' => Customer::factory()->create()->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Negotiated box line',
                'quantity' => 2,
                'uom_id' => $this->boxUom->id,
                'unit_price' => 9.50, // per-base negotiated price
                'tax_rate' => 0,
            ]],
        ]);

        $line = $order->lines()->first();

        $this->assertSame(24.0, (float) $line->quantity, '2 Boxes must become 24 base units');
        $this->assertSame(9.5, (float) $line->unit_price);
    }

    public function test_uom_from_another_product_is_rejected(): void
    {
        $otherProduct = Product::factory()->create(['price' => 20, 'cost' => 5]);
        $foreignUom = ProductUom::create([
            'product_id' => $otherProduct->id,
            'name' => 'Case',
            'abbreviation' => 'Case',
            'conversion_factor' => 6,
            'price_multiplier' => 1,
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);

        $this->orderWithUom($foreignUom->id, 1);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Purchasing / goods receipt
    // ─────────────────────────────────────────────────────────────────────

    public function test_goods_receipt_in_non_base_uom_is_converted_to_base_units(): void
    {
        $supplier = Supplier::create([
            'name' => 'UOM Supplier',
            'code' => 'UOMS-'.Str::upper(Str::random(5)),
            'is_active' => true,
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-UOM-'.Str::upper(Str::random(6)),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'subtotal' => 120,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'total' => 120,
            'currency' => 'USD',
            'created_by' => $this->user->id,
        ]);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $this->product->id,
            'description' => 'UOM item',
            'quantity_ordered' => 24,
            'quantity_received' => 0,
            'unit_cost' => 5,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_percent' => 0,
            'line_total' => 120,
        ]);

        $grn = app(\App\Services\GoodsReceiptService::class)->create([
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => now()->toDateString(),
            'lines' => [[
                'purchase_order_item_id' => $item->id,
                'quantity_received' => 2, // 2 Boxes
                'quantity_accepted' => 2,
                'quantity_rejected' => 0,
                'uom_id' => $this->boxUom->id,
            ]],
        ]);

        $line = $grn->lines()->first();

        $this->assertSame(24.0, (float) $line->quantity_received, '2 Boxes must become 24 base units');
        $this->assertSame(24.0, (float) $line->quantity_accepted);
        $this->assertSame(0.0, (float) $line->quantity_rejected);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Canonical conversion helpers
    // ─────────────────────────────────────────────────────────────────────

    public function test_converter_is_the_single_conversion_authority(): void
    {
        $converter = app(\App\Services\ProductUomConverter::class);

        $this->assertSame(12.0, $converter->toBase($this->boxUom, 1.0));
        $this->assertSame(30.0, $converter->toBase($this->boxUom, 2.5));
        $this->assertSame(10.0, $converter->baseUnitPrice($this->product, $this->boxUom));
        $this->assertSame(120.0, $this->boxUom->calculatePrice(10.0));
    }

    public function test_backfill_migration_columns_exist(): void
    {
        // Guard: the UOM table columns this feature depends on are present.
        foreach (['conversion_factor', 'price_multiplier', 'is_sales_unit', 'is_purchase_unit', 'abbreviation'] as $column) {
            $this->assertTrue(
                DB::getSchemaBuilder()->hasColumn('product_uoms', $column),
                "product_uoms.{$column} is required for UOM conversion"
            );
        }
    }
}
