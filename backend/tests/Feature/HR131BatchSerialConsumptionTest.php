<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BatchConsumption;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BatchSerialConsumptionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Services\InventoryService;
use Modules\Sales\Services\DeliveryOrderService;
use Tests\TenantTestCase;

/**
 * Issue #131 (HIGH) — ProductBatch / ProductSerial were built but no sale flow
 * consumed them. This covers the four implementation steps:
 *   P1 FEFO consumption + ledger (via InventoryService, so all channels benefit)
 *   P2 FEFO cost preview for GL-before-stock COGS
 *   P3 serials marked sold at fulfilment
 *   P4 return restoration from the ledger + reconciliation command
 */
class HR131BatchSerialConsumptionTest extends TenantTestCase
{
    private Warehouse $warehouse;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = (string) Str::uuid();
        $this->bindTenantContext($tenantId);
        $this->assertTenantSchemaReady(['products', 'product_batches', 'product_serials', 'batch_consumptions', 'stock_levels']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $tenantId,
            'name' => 'Batch Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->warehouse = Warehouse::create([
            'name' => 'Batch Warehouse',
            'code' => 'BCH-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function trackedProduct(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'name' => 'Batch Widget '.Str::random(4),
            'sku' => 'BCH-'.uniqid(),
            'track_inventory' => true,
            'stock_quantity' => 0,
            'cost' => 10.00,
            'price' => 20.00,
        ], $overrides));
    }

    /** Two FEFO layers: 5 @ 8 expiring sooner, 10 @ 12 expiring later. */
    private function stockedProductWithLayers(): Product
    {
        $product = $this->trackedProduct();

        ProductBatch::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_number' => 'LOT-EARLY-'.Str::upper(Str::random(4)),
            'quantity' => 5,
            'quantity_reserved' => 0,
            'cost' => 8.00,
            'expiry_date' => now()->addMonth()->toDateString(),
            'received_date' => now()->subMonth()->toDateString(),
            'status' => ProductBatch::STATUS_AVAILABLE,
        ]);

        ProductBatch::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_number' => 'LOT-LATE-'.Str::upper(Str::random(4)),
            'quantity' => 10,
            'quantity_reserved' => 0,
            'cost' => 12.00,
            'expiry_date' => now()->addYear()->toDateString(),
            'received_date' => now()->subWeek()->toDateString(),
            'status' => ProductBatch::STATUS_AVAILABLE,
        ]);

        StockLevel::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 15,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);

        return $product;
    }

    private function batchesOf(Product $product): array
    {
        return ProductBatch::where('product_id', $product->id)
            ->orderBy('expiry_date')
            ->pluck('quantity', 'batch_number')
            ->map(fn ($qty) => (int) $qty)
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────
    // P1 — FEFO consumption + ledger
    // ─────────────────────────────────────────────────────────────────────

    public function test_sale_consumes_batches_in_fefo_order_and_records_the_ledger(): void
    {
        $product = $this->stockedProductWithLayers();

        app(InventoryService::class)->adjustStock(
            productId: $product->id,
            warehouseId: $this->warehouse->id,
            quantity: 7,
            type: 'sale',
            reference: 'TEST-SALE-1',
            referenceType: 'TestSale',
        );

        // 5 from the early layer (cost 8) + 2 from the late layer (cost 12).
        $batches = $this->batchesOf($product);
        $early = $batches[array_key_first($batches)];
        $late = $batches[array_key_last($batches)];

        $this->assertSame(0, $early, 'Earliest-expiry layer must be consumed first');
        $this->assertSame(8, $late, 'Remainder comes from the later layer');

        $ledger = BatchConsumption::where('product_id', $product->id)->orderBy('id')->get();
        $this->assertCount(2, $ledger);
        $this->assertSame(5, (int) $ledger[0]->quantity);
        $this->assertSame(8.0, (float) $ledger[0]->unit_cost);
        $this->assertSame(2, (int) $ledger[1]->quantity);
        $this->assertSame(12.0, (float) $ledger[1]->unit_cost);

        // Warehouse stock still authoritative and consistent.
        $this->assertSame(8, (int) StockLevel::where('product_id', $product->id)->where('warehouse_id', $this->warehouse->id)->value('quantity'));
    }

    public function test_repeated_sale_call_is_idempotent_for_the_same_key(): void
    {
        $product = $this->stockedProductWithLayers();

        $service = app(InventoryService::class);

        $service->adjustStock($product->id, $this->warehouse->id, 5, 'sale', 'IDEM-1', 'TestSale', null, null, null, null, null, 'idem-key-1');

        // Retry with the same idempotency key must not consume again.
        $service->adjustStock($product->id, $this->warehouse->id, 5, 'sale', 'IDEM-1', 'TestSale', null, null, null, null, null, 'idem-key-1');

        $this->assertSame(1, BatchConsumption::where('product_id', $product->id)->count(), 'Ledger must not double-consume on retry');
    }

    // ─────────────────────────────────────────────────────────────────────
    // P2 — deterministic FEFO cost preview (GL-before-stock)
    // ─────────────────────────────────────────────────────────────────────

    public function test_preview_cost_matches_the_realized_consumption(): void
    {
        $product = $this->stockedProductWithLayers();
        $service = app(BatchSerialConsumptionService::class);

        $preview = $service->previewFefoCost($product, $this->warehouse->id, 7);

        // 5 x 8.00 + 2 x 12.00 = 64.00
        $this->assertSame(64.0, $preview);

        app(InventoryService::class)->adjustStock($product->id, $this->warehouse->id, 7, 'sale', 'COGS-1', 'TestSale');

        $realized = (float) BatchConsumption::where('product_id', $product->id)
            ->get()
            ->sum(fn ($row) => (int) $row->quantity * (float) $row->unit_cost);

        $this->assertSame($preview, round($realized, 2), 'Preview must equal the realized layer cost');
    }

    public function test_preview_returns_null_when_the_product_has_no_batches(): void
    {
        $product = $this->trackedProduct(['stock_quantity' => 0]);

        $this->assertNull(
            app(BatchSerialConsumptionService::class)->previewFefoCost($product, $this->warehouse->id, 3),
            'No batches means the caller falls back to weighted-average costing (#107)'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // P3 — serials marked sold at fulfilment
    // ─────────────────────────────────────────────────────────────────────

    private function serialProduct(): Product
    {
        $product = Product::factory()->create([
            'name' => 'Serial Widget '.Str::random(4),
            'sku' => 'SER-'.uniqid(),
            'track_inventory' => true,
            'tracking_method' => Product::TRACKING_SERIAL,
            'stock_quantity' => 0,
            'cost' => 50.00,
            'price' => 100.00,
            'warranty_months' => 12,
        ]);

        StockLevel::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 5,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);

        return $product;
    }

    private function serial(Product $product, string $number): ProductSerial
    {
        return ProductSerial::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'serial_number' => $number,
            'status' => ProductSerial::STATUS_AVAILABLE,
            'cost' => 50.00,
        ]);
    }

    public function test_delivering_serialised_units_marks_serials_sold(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 10:00:00'));

        $product = $this->serialProduct();
        $serial1 = $this->serial($product, 'SN-'.Str::upper(Str::random(6)));
        $serial2 = $this->serial($product, 'SN-'.Str::upper(Str::random(6)));

        $customer = Customer::factory()->create();
        $service = app(DeliveryOrderService::class);

        $do = $service->createStandaloneDeliveryOrder([
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Serialised unit',
                'quantity_delivered' => 2,
                'serial_numbers' => [$serial1->serial_number, $serial2->serial_number],
            ]],
        ]);

        $service->completeDeliveryOrder($do);

        $serial1->refresh();
        $serial2->refresh();

        $this->assertSame(ProductSerial::STATUS_SOLD, $serial1->status);
        $this->assertSame($customer->id, (int) $serial1->customer_id);
        $this->assertNotNull($serial1->sold_at);
        $this->assertSame('2026-09-21', $serial1->sold_at->toDateString());
        $this->assertSame('2027-09-21', $serial1->warranty_end_date->toDateString(), '12-month warranty from the sale date');
        $this->assertSame($do->delivery_order_number, $serial1->sale_reference);
        $this->assertSame(ProductSerial::STATUS_SOLD, $serial2->status);

        Carbon::setTestNow();
    }

    public function test_serial_count_must_match_the_delivered_quantity(): void
    {
        $product = $this->serialProduct();
        $serial = $this->serial($product, 'SN-'.Str::upper(Str::random(6)));

        $this->expectException(ValidationException::class);

        app(DeliveryOrderService::class)->createStandaloneDeliveryOrder([
            'customer_id' => Customer::factory()->create()->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Too few serials',
                'quantity_delivered' => 2,
                'serial_numbers' => [$serial->serial_number],
            ]],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // P4 — return restoration + reconciliation command
    // ─────────────────────────────────────────────────────────────────────

    public function test_return_restores_the_consumed_layers_from_the_ledger(): void
    {
        $product = $this->stockedProductWithLayers();
        $service = app(InventoryService::class);

        $service->adjustStock($product->id, $this->warehouse->id, 7, 'sale', 'RET-SALE', 'TestSale');
        $service->adjustStock($product->id, $this->warehouse->id, 7, 'return', 'RET-REFUND', 'TestReturn');

        $batches = array_values($this->batchesOf($product));

        $this->assertSame(5, $batches[0], 'Early layer fully restored');
        $this->assertSame(10, $batches[1], 'Late layer fully restored');
        $this->assertSame(15, (int) StockLevel::where('product_id', $product->id)->where('warehouse_id', $this->warehouse->id)->value('quantity'));
    }

    public function test_reconciliation_command_detects_batch_drift(): void
    {
        $product = $this->stockedProductWithLayers();

        // Consistent state: command is clean.
        Artisan::call('inventory:reconcile-batches');
        $this->assertStringContainsString('clean', Artisan::output());

        // Introduce drift: stock level no longer matches the layers.
        StockLevel::where('product_id', $product->id)->where('warehouse_id', $this->warehouse->id)->update(['quantity' => 6]);

        Artisan::call('inventory:reconcile-batches');
        $output = Artisan::output();

        $this->assertStringContainsString('drift', strtolower($output));
        $this->assertStringContainsString((string) $product->id, $output);
    }
}
