<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductBundle;
use App\Models\ProductSerial;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Sales\Models\DeliveryOrderLine;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #460 (P1, DO8) — completion and reversal handled stock differently:
 *
 *   • Completion decomposed bundles (#130) and deducted their COMPONENTS;
 *     reversal restocked only the line's own product when it was
 *     stock-tracked — a virtual bundle was never stock-tracked, so its
 *     components were never put back at all.
 *   • Completion passed the line's lot / expiry to the stock movement;
 *     reversal passed neither, so a reversed lot delivery left a `return`
 *     movement with no lot and no expiry (FEFO trail broken).
 *   • Completion marked the line's serials sold (#131); reversal never
 *     un-marked them — a reversed delivery left the serials unsellable.
 *
 * The acceptance rule: a reversal must replay exactly the stock movements
 * completion recorded, in reverse — same component, quantity, lot and expiry —
 * and return the serials to stock.
 *
 * Every test drives the real routes (router → middleware → controller):
 * the delivery order is created, completed and reversed through the
 * DeliveryOrderController, and the assertions read the real stock ledger.
 * One fixture exception is disclosed: the standalone create route has no
 * `lines.*.serial_numbers` validation rule (the payload key is dropped), so
 * the serial test attaches the serials to the created line before completing;
 * completion and reversal themselves run through the real route.
 */
class HR460DeliveryReverseReplaysStockTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'DO Reversal Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);

        // Completion posts COGS through PostingEngine, which requires an open
        // fiscal period.
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
                'created_by' => $this->admin->id,
            ]
        );

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 1000000]);

        $this->warehouse = Warehouse::create([
            'name' => 'DO Reversal Warehouse',
            'code' => 'DOR-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')->andReturnUsing(fn (string $tenantId, string $key) => [
            'key' => $key,
            'is_entitled' => $isEntitled,
            'source' => $isEntitled ? 'tier_included' : null,
            'name' => strtoupper($key),
        ]);

        $this->app->instance(FeatureGateService::class, $mock);
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers — the delivery lifecycle runs through the real routes
    // ─────────────────────────────────────────────────────────────────────

    private function standaloneDeliveryOrder(array $lines): int
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/sales/delivery-orders', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => $lines,
        ]);
        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    private function completeDeliveryOrder(int $deliveryOrderId): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrderId}/complete");
    }

    private function reverseDeliveryOrder(int $deliveryOrderId): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrderId}/reverse", [
                'reason' => 'Issue #460 reversal test',
            ]);
    }

    private function stockLevelOf(Product $product): float
    {
        return (float) StockLevel::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('quantity');
    }

    private function trackedProduct(string $name, string $prefix, float $cost = 10.0): Product
    {
        $product = Product::factory()->create([
            'name' => $name,
            'sku' => $prefix.'-'.uniqid(),
            'track_inventory' => true,
            'cost' => $cost,
            'price' => 25,
            'stock_quantity' => 0,
        ]);

        StockLevel::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 10,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);

        return $product;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 1 — bundle: reversal must restore the components completion consumed
    // ═══════════════════════════════════════════════════════════════════════

    public function test_reversing_a_bundle_delivery_restores_the_components_completion_consumed(): void
    {
        // Bundle is virtual (assembled on sale): 1 basket = 2 wine + 1 chocolate.
        $bundle = Product::factory()->create([
            'name' => 'Gift Basket',
            'sku' => 'BND-'.uniqid(),
            'is_bundle' => true,
            'track_inventory' => false,
            'price' => 50,
            'cost' => 0,
        ]);
        $wine = $this->trackedProduct('Red Wine', 'CMP-A', 5.00);
        $chocolate = $this->trackedProduct('Chocolate', 'CMP-B', 3.00);

        ProductBundle::create([
            'bundle_product_id' => $bundle->id,
            'component_product_id' => $wine->id,
            'quantity' => 2,
        ]);
        ProductBundle::create([
            'bundle_product_id' => $bundle->id,
            'component_product_id' => $chocolate->id,
            'quantity' => 1,
        ]);

        $deliveryOrderId = $this->standaloneDeliveryOrder([[
            'line_type' => 'product',
            'product_id' => $bundle->id,
            'description' => 'Gift Basket',
            'quantity_delivered' => 3,
        ]]);

        $this->completeDeliveryOrder($deliveryOrderId)->assertOk();

        // 3 baskets = 6 wine + 3 chocolate leave the warehouse.
        $this->assertSame(4.0, $this->stockLevelOf($wine), 'Completion must consume 2 x 3 wine');
        $this->assertSame(7.0, $this->stockLevelOf($chocolate), 'Completion must consume 1 x 3 chocolate');

        $saleMovements = StockMovement::query()
            ->where('reference_type', 'DeliveryOrder')
            ->where('reference_id', $deliveryOrderId)
            ->where('type', 'sale')
            ->get()
            ->keyBy('product_id');
        $this->assertSame(6.0, (float) $saleMovements[$wine->id]->quantity);
        $this->assertSame(3.0, (float) $saleMovements[$chocolate->id]->quantity);

        $this->reverseDeliveryOrder($deliveryOrderId)->assertOk();

        // The reversal must put back exactly the components completion took.
        $this->assertSame(10.0, $this->stockLevelOf($wine),
            'Reversal must restock the 6 wine components the bundle delivery consumed');
        $this->assertSame(10.0, $this->stockLevelOf($chocolate),
            'Reversal must restock the 3 chocolate components the bundle delivery consumed');

        $returnMovements = StockMovement::query()
            ->where('reference_type', 'DeliveryOrder')
            ->where('reference_id', $deliveryOrderId)
            ->where('type', 'return')
            ->get()
            ->keyBy('product_id');

        $this->assertCount(2, $returnMovements,
            'One compensating movement per component the completion issued');
        $this->assertSame(6.0, (float) $returnMovements[$wine->id]->quantity,
            'The wine reversal must mirror the completion movement exactly');
        $this->assertSame(3.0, (float) $returnMovements[$chocolate->id]->quantity,
            'The chocolate reversal must mirror the completion movement exactly');

        // Net issued per component is zero — the trail balances.
        foreach ($saleMovements as $productId => $sale) {
            $this->assertSame(0.0, (float) $sale->quantity - (float) $returnMovements[$productId]->quantity,
                "Component {$productId} must net to zero after reversal");
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 2 — lot/expiry: reversal must replay the same lot and expiry
    // ═══════════════════════════════════════════════════════════════════════

    public function test_reversing_a_lot_delivery_restores_the_same_lot_and_expiry(): void
    {
        $product = $this->trackedProduct('Lot Widget', 'LOT-460', 10.00);

        $batch = ProductBatch::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_number' => 'LOT-460-A',
            'lot_number' => 'LOT-460-A',
            'quantity' => 10,
            'quantity_reserved' => 0,
            'cost' => 10.00,
            'status' => ProductBatch::STATUS_AVAILABLE,
            'received_date' => now()->subMonth()->toDateString(),
            'expiry_date' => '2027-01-31',
        ]);

        $deliveryOrderId = $this->standaloneDeliveryOrder([[
            'line_type' => 'product',
            'product_id' => $product->id,
            'description' => 'Lot Widget',
            'quantity_delivered' => 4,
            'lot_number' => 'LOT-460-A',
            'batch_number' => 'LOT-460-A',
            'product_batch_id' => $batch->id,
            'expiry_date' => '2027-01-31',
        ]]);

        $this->completeDeliveryOrder($deliveryOrderId)->assertOk();

        $sale = StockMovement::query()
            ->where('reference_type', 'DeliveryOrder')
            ->where('reference_id', $deliveryOrderId)
            ->where('type', 'sale')
            ->firstOrFail();

        $this->assertSame('LOT-460-A', $sale->batch_number,
            'Completion must record the line lot on the issue movement');
        $this->assertSame('2027-01-31', $sale->expiry_date?->toDateString(),
            'Completion must record the line expiry on the issue movement');
        $this->assertSame(6.0, $this->stockLevelOf($product));
        $this->assertSame(6, (int) $batch->fresh()->quantity, 'The named lot layer must be consumed');

        $this->reverseDeliveryOrder($deliveryOrderId)->assertOk();

        $return = StockMovement::query()
            ->where('reference_type', 'DeliveryOrder')
            ->where('reference_id', $deliveryOrderId)
            ->where('type', 'return')
            ->firstOrFail();

        $this->assertSame($sale->product_id, $return->product_id,
            'The reversal must restock the product completion issued');
        $this->assertSame((float) $sale->quantity, (float) $return->quantity,
            'The reversal must mirror the issue quantity exactly');
        $this->assertSame($sale->batch_number, $return->batch_number,
            'The reversal movement must carry the SAME lot as the original issue');
        $this->assertSame(
            $sale->expiry_date?->toDateString(),
            $return->expiry_date?->toDateString(),
            'The reversal movement must carry the SAME expiry as the original issue'
        );

        $this->assertSame(10.0, $this->stockLevelOf($product), 'Stock must be whole again');
        $this->assertSame(10, (int) $batch->fresh()->quantity,
            'The original lot layer must be restored, not a different one');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 3 — serials: reversal must return them to stock
    // ═══════════════════════════════════════════════════════════════════════

    public function test_reversing_a_delivery_returns_the_serials_to_stock(): void
    {
        // tracking_method stays non-serial so the create route accepts the
        // line; the completion/serial lifecycle is exercised by attaching the
        // serials below (the route has no serial_numbers rule — see class doc).
        $product = $this->trackedProduct('Serial Widget', 'SN-460', 50.00);

        $serialOne = ProductSerial::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'serial_number' => 'SN-460-0001',
            'status' => ProductSerial::STATUS_AVAILABLE,
            'cost' => 50.00,
        ]);
        $serialTwo = ProductSerial::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'serial_number' => 'SN-460-0002',
            'status' => ProductSerial::STATUS_AVAILABLE,
            'cost' => 50.00,
        ]);

        $deliveryOrderId = $this->standaloneDeliveryOrder([[
            'line_type' => 'product',
            'product_id' => $product->id,
            'description' => 'Serial Widget',
            'quantity_delivered' => 2,
        ]]);

        // Route fixture: the standalone create route validates serial_number /
        // product_serial_id but not serial_numbers, so attach the serials the
        // delivery ships before completing it.
        DeliveryOrderLine::query()
            ->where('delivery_order_id', $deliveryOrderId)
            ->update(['serial_numbers' => [$serialOne->serial_number, $serialTwo->serial_number]]);

        $this->completeDeliveryOrder($deliveryOrderId)->assertOk();

        $this->assertSame(ProductSerial::STATUS_SOLD, $serialOne->fresh()->status,
            'Completion marks the shipped serials sold (#131)');
        $this->assertSame(ProductSerial::STATUS_SOLD, $serialTwo->fresh()->status);

        $this->reverseDeliveryOrder($deliveryOrderId)->assertOk();

        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $serialOne->fresh()->status,
            'Reversal must return the serial to stock so it is sellable again');
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $serialTwo->fresh()->status,
            'Reversal must return every serial of the line to stock');
        $this->assertSame(10.0, $this->stockLevelOf($product), 'And the quantity must be back on hand');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 4 — idempotency: a reversed delivery cannot be reversed twice
    // ═══════════════════════════════════════════════════════════════════════

    public function test_reversing_the_same_delivery_twice_is_refused_and_does_not_restock_again(): void
    {
        $product = $this->trackedProduct('Twice Widget', 'TWICE-460', 10.00);

        $deliveryOrderId = $this->standaloneDeliveryOrder([[
            'line_type' => 'product',
            'product_id' => $product->id,
            'description' => 'Twice Widget',
            'quantity_delivered' => 4,
        ]]);

        $this->completeDeliveryOrder($deliveryOrderId)->assertOk();
        $this->reverseDeliveryOrder($deliveryOrderId)->assertOk();

        $this->assertSame(10.0, $this->stockLevelOf($product));
        $movementCount = StockMovement::query()
            ->where('reference_type', 'DeliveryOrder')
            ->where('reference_id', $deliveryOrderId)
            ->count();

        $second = $this->reverseDeliveryOrder($deliveryOrderId);
        $second->assertStatus(422);

        $this->assertSame(10.0, $this->stockLevelOf($product),
            'A refused second reversal must not restock the goods a second time');
        $this->assertSame($movementCount, StockMovement::query()
            ->where('reference_type', 'DeliveryOrder')
            ->where('reference_id', $deliveryOrderId)
            ->count(), 'A refused second reversal must append no movement');
    }
}
