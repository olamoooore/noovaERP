<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FiscalPeriod;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BundleDecompositionService;
use App\Services\GoodsReceiptService;
use Illuminate\Support\Str;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\PurchaseOrderItem;
use Modules\Inventory\Models\Supplier;
use Tests\TenantTestCase;

/**
 * Issue #107 (HIGH) — Product.cost was static: no purchase flow recalculated it,
 * so every COGS posting used a stale value. Goods receipts now fold each
 * receipt into the product's weighted-average cost, in the same transaction as
 * the stock movement.
 *
 * Verification from the issue: receive two POs at different unit costs, assert
 * the weighted average is correct after each receipt, and assert the value COGS
 * uses reflects it.
 */
class HR107WeightedAverageCostTest extends TenantTestCase
{
    private Product $product;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private User $user;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContextWithPermissions($this->tenantId);
        $this->assertTenantSchemaReady(['products', 'purchase_orders', 'goods_receipts', 'stock_levels']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Costing Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->user = User::create([
            'name' => 'Costing User',
            'email' => 'hr107-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->actingAs($this->user);

        FiscalPeriod::firstOrCreate(
            ['code' => now()->format('Y-m')],
            [
                'name' => now()->format('F Y'),
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => (int) now()->format('Y'),
                'is_locked' => false,
                'status' => 'open',
                'created_by' => $this->user->id,
            ]
        );

        $this->supplier = Supplier::create([
            'name' => 'Costing Supplier',
            'code' => 'COST-'.Str::upper(Str::random(5)),
            'is_active' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Costing Warehouse',
            'code' => 'COSTW-'.uniqid(),
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Costing Widget',
            'sku' => 'COST-'.uniqid(),
            'track_inventory' => true,
            'cost' => 10.00,
            'price' => 20.00,
            // Explicitly start from zero on-hand so the weighted average has a
            // clean basis (the factory otherwise seeds stock_quantity).
            'stock_quantity' => 0,
        ]);
    }

    /**
     * Issue #249 — investigated: the hostile-review scenario (a free line
     * followed by a paid line for the same product in one GRN) is UNREACHABLE
     * because stock_movements_ref_product_type_unique (2026_04_27) rejects a
     * second movement for the same (reference_type, reference_id, product_id,
     * type). This test documents that guard. The caller-side quantity folding
     * was still hardened to advance the basis for zero-cost lines (defensive;
     * required if that constraint is ever relaxed to be line-scoped).
     */
    public function test_multi_line_same_product_grn_is_blocked_by_the_movement_unique_constraint(): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-COST-'.Str::upper(Str::random(6)),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'subtotal' => 160,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'total' => 160,
            'currency' => 'USD',
            'created_by' => $this->user->id,
        ]);

        $mkItem = fn (int $qty, float $cost) => PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $this->product->id,
            'description' => 'Line',
            'quantity_ordered' => $qty,
            'quantity_received' => 0,
            'unit_cost' => $cost,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_percent' => 0,
            'line_total' => $qty * $cost,
        ]);
        $freeItem = $mkItem(5, 0);
        $paidItem = $mkItem(10, 16);

        $service = app(GoodsReceiptService::class);
        $grn = $service->create([
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => now()->toDateString(),
            'lines' => [
                ['purchase_order_item_id' => $freeItem->id, 'quantity_received' => 5, 'quantity_accepted' => 5, 'quantity_rejected' => 0],
                ['purchase_order_item_id' => $paidItem->id, 'quantity_received' => 10, 'quantity_accepted' => 10, 'quantity_rejected' => 0],
            ],
        ]);

        try {
            $service->confirm($grn);
            $this->fail('Expected the per-reference movement uniqueness guard to reject the second line');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('stock_movements_ref_product_type_unique', $e->getMessage());
        }

        // The transaction rolled back: no partial stock/cost mutation.
        $this->assertSame(0.0, (float) $this->product->fresh()->stock_quantity);
        $this->assertSame(10.00, $this->cost());
    }

    private function receive(int $qty, float $unitCost): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-COST-'.Str::upper(Str::random(6)),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'subtotal' => $qty * $unitCost,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'total' => $qty * $unitCost,
            'currency' => 'USD',
            'created_by' => $this->user->id,
        ]);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $this->product->id,
            'description' => 'Costing item',
            'quantity_ordered' => $qty,
            'quantity_received' => 0,
            'unit_cost' => $unitCost,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_percent' => 0,
            'line_total' => $qty * $unitCost,
        ]);

        $service = app(GoodsReceiptService::class);

        $grn = $service->create([
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => now()->toDateString(),
            'lines' => [[
                'purchase_order_item_id' => $item->id,
                'quantity_received' => $qty,
                'quantity_accepted' => $qty,
                'quantity_rejected' => 0,
            ]],
        ]);

        $service->confirm($grn);
    }

    private function cost(): float
    {
        return (float) $this->product->fresh()->cost;
    }

    // ─────────────────────────────────────────────────────────────────────
    // The issue's verification
    // ─────────────────────────────────────────────────────────────────────

    public function test_weighted_average_is_recalculated_from_receipts(): void
    {
        // First receipt: 10 @ 12 from zero stock -> average 12.00
        $this->receive(10, 12.00);

        $this->assertSame(12.0, $this->cost(), 'First receipt must set the weighted average');
        $this->assertSame(10, (int) $this->product->fresh()->stock_quantity);

        // Second receipt: 10 @ 16 -> (10x12 + 10x16) / 20 = 14.00
        $this->receive(10, 16.00);

        $this->assertSame(14.0, $this->cost(), 'Second receipt must fold into the average');
        $this->assertSame(20, (int) $this->product->fresh()->stock_quantity);
    }

    public function test_cogs_value_reflects_the_recalculated_average(): void
    {
        $this->receive(10, 12.00);
        $this->receive(10, 16.00); // average 14.00

        // The COGS paths (InvoiceService, POS, Ecommerce) resolve line cost via
        // this service / Product.cost — both now see 14.00.
        $cogs = app(BundleDecompositionService::class)->cost($this->product->fresh(), 2);

        $this->assertSame(28.0, $cogs, 'COGS for 2 units must use the recalculated 14.00 average');
    }

    public function test_zero_cost_receipt_does_not_dilute_the_average(): void
    {
        $this->receive(10, 12.00); // average 12.00, stock 10

        $this->receive(5, 0.00); // free goods

        $this->assertSame(12.0, $this->cost(), 'A zero-cost receipt must not change the average');
        $this->assertSame(15, (int) $this->product->fresh()->stock_quantity, 'Stock still increases');
    }

    public function test_average_is_weighted_not_naively_overwritten(): void
    {
        // Stock the product first: 30 @ 10 -> 10.00
        $this->receive(30, 10.00);
        $this->assertSame(10.0, $this->cost());

        // Receive 10 @ 20 -> (30x10 + 10x20) / 40 = 12.50
        $this->receive(10, 20.00);

        $this->assertSame(12.5, $this->cost(), 'Average must be quantity-weighted, not last-cost');
        $this->assertSame(40, (int) $this->product->fresh()->stock_quantity);
    }
}
