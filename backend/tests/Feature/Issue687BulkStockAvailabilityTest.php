<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\PurchaseOrderItem;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Models\Supplier;
use Modules\Inventory\Models\WarehouseTransfer;
use Modules\Inventory\Models\WarehouseTransferItem;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #687 [SALES-INV] UX1 — available-to-promise on sales lines.
 *
 * The #465 single-product stock check (GET /sales/delivery-orders/stock-check)
 * is extended with a bulk POST that accepts the document's product lines and
 * reports on hand / reserved / available / incoming per line, plus a shortage
 * summary for the confirm dialog.
 *
 * These tests drive the REAL routes (router → middleware → controller). The
 * reuse requirement is the point: every figure comes from
 * InventoryService::stockAvailability() (#465/#686) — the bulk path must not
 * fork the formula.
 *
 * RED evidence on base: the POST route does not exist, so every bulk test
 * fails with 405 Method Not Allowed.
 */
class Issue687BulkStockAvailabilityTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Warehouse $warehouse;

    private Warehouse $otherWarehouse;

    private Product $productA;

    private Product $productB;

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
            'name' => 'Bulk Availability Tenant',
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

        $this->warehouse = Warehouse::create([
            'name' => 'Bulk Availability Warehouse',
            'code' => 'BAW-'.uniqid(),
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->otherWarehouse = Warehouse::create([
            'name' => 'Other Warehouse',
            'code' => 'BAW-OTHER-'.uniqid(),
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->productA = Product::factory()->create([
            'name' => 'Bulk Widget A',
            'sku' => 'BW-A-'.uniqid(),
            'track_inventory' => true,
            'cost' => 10,
            'price' => 25,
            'stock_quantity' => 0,
        ]);

        $this->productB = Product::factory()->create([
            'name' => 'Bulk Widget B',
            'sku' => 'BW-B-'.uniqid(),
            'track_inventory' => true,
            'cost' => 10,
            'price' => 25,
            'stock_quantity' => 0,
        ]);

        $this->stockLevel($this->productA, $this->warehouse, 10);
        $this->stockLevel($this->productB, $this->warehouse, 2);
    }

    private function stockLevel(Product $product, Warehouse $warehouse, float $quantity): StockLevel
    {
        return StockLevel::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
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

    private function bulkCheck(array $lines): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/sales/delivery-orders/stock-check', [
            'warehouse_id' => $this->warehouse->id,
            'lines' => $lines,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Per-line on hand / reserved / available + shortage summary
    // ═══════════════════════════════════════════════════════════════════════

    public function test_bulk_stock_check_reports_on_hand_reserved_and_available_per_line(): void
    {
        // Another document holds 4 of A's 10 units.
        StockReservation::create([
            'product_id' => $this->productA->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 4,
            'quantity_fulfilled' => 0,
            'status' => 'open',
            'source_document_type' => 'SalesOrder',
            'source_document_id' => 999,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->bulkCheck([
            ['product_id' => $this->productA->id, 'quantity' => 5],
            ['product_id' => $this->productB->id, 'quantity' => 3],
        ]);

        $response->assertOk();
        $response->assertJsonPath('summary.line_count', 2);

        $lines = $response->json('lines');
        $this->assertCount(2, $lines);

        // A: 10 on hand − 4 reserved = 6 available; 5 requested fits.
        $this->assertSame($this->productA->id, (int) $lines[0]['product_id']);
        $this->assertEqualsWithDelta(10.0, (float) $lines[0]['on_hand'], 0.0001);
        $this->assertEqualsWithDelta(4.0, (float) $lines[0]['reserved'], 0.0001);
        $this->assertEqualsWithDelta(6.0, (float) $lines[0]['available'], 0.0001);
        $this->assertTrue($lines[0]['sufficient']);
        $this->assertEqualsWithDelta(0.0, (float) $lines[0]['shortage'], 0.0001);

        // B: only 2 available; 3 requested → 1 short.
        $this->assertSame($this->productB->id, (int) $lines[1]['product_id']);
        $this->assertEqualsWithDelta(2.0, (float) $lines[1]['on_hand'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $lines[1]['reserved'], 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $lines[1]['available'], 0.0001);
        $this->assertFalse($lines[1]['sufficient']);
        $this->assertEqualsWithDelta(1.0, (float) $lines[1]['shortage'], 0.0001);

        $response->assertJsonPath('summary.lines_short', 1);
    }

    public function test_bulk_stock_check_requires_warehouse_and_lines(): void
    {
        $this->postJson('/api/v1/sales/delivery-orders/stock-check', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id', 'lines']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Incoming supply: open purchase orders / inbound transfers
    // ═══════════════════════════════════════════════════════════════════════

    private function createPurchaseOrder(Product $product, string $status, float $ordered, float $received, ?string $expectedDate = null): PurchaseOrder
    {
        $supplier = Supplier::firstOrCreate(
            ['code' => 'SUP-687'],
            ['name' => 'Bulk Availability Supplier', 'is_active' => true],
        );

        $po = PurchaseOrder::create([
            'po_number' => 'PO-687-'.uniqid(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => $status,
            'order_date' => now()->toDateString(),
            'expected_date' => $expectedDate,
            'created_by' => $this->admin->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'description' => $product->name,
            'quantity_ordered' => $ordered,
            'quantity_received' => $received,
            'unit_cost' => 10,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_percent' => 0,
            'line_total' => $ordered * 10,
        ]);

        return $po;
    }

    public function test_bulk_stock_check_reports_incoming_from_open_purchase_orders(): void
    {
        $expectedDate = now()->addDays(7)->toDateString();
        $openPo = $this->createPurchaseOrder($this->productA, 'ordered', 5, 1, $expectedDate);

        // Draft / received POs are not incoming supply.
        $this->createPurchaseOrder($this->productA, 'draft', 9, 0, now()->addDay()->toDateString());
        $this->createPurchaseOrder($this->productA, 'received', 9, 9, now()->subDay()->toDateString());

        $response = $this->bulkCheck([
            ['product_id' => $this->productA->id, 'quantity' => 1],
        ]);

        $response->assertOk();

        $incoming = $response->json('lines.0.incoming');
        $this->assertCount(1, $incoming, 'Only the open PO line is incoming supply');
        $this->assertSame('purchase_order', $incoming[0]['source_type']);
        $this->assertSame($openPo->id, (int) $incoming[0]['source_id']);
        $this->assertSame($openPo->po_number, $incoming[0]['reference']);
        $this->assertEqualsWithDelta(4.0, (float) $incoming[0]['quantity'], 0.0001, '5 ordered − 1 received');
        $this->assertSame($expectedDate, $incoming[0]['expected_date']);
        $this->assertSame('ordered', $incoming[0]['status']);

        $this->assertEqualsWithDelta(4.0, (float) $response->json('lines.0.incoming_quantity'), 0.0001);
    }

    public function test_bulk_stock_check_reports_incoming_from_inbound_transfers(): void
    {
        $pendingExpected = now()->addDays(3)->toDateString();
        $pending = WarehouseTransfer::create([
            'transfer_number' => 'TRF-687-'.uniqid(),
            'from_warehouse_id' => $this->otherWarehouse->id,
            'to_warehouse_id' => $this->warehouse->id,
            'status' => 'pending',
            'transfer_date' => now()->toDateString(),
            'expected_date' => $pendingExpected,
            'created_by' => $this->admin->id,
        ]);
        WarehouseTransferItem::create([
            'warehouse_transfer_id' => $pending->id,
            'product_id' => $this->productA->id,
            'quantity_requested' => 6,
            'quantity_sent' => 0,
            'quantity_received' => 0,
        ]);

        // In transit: only what was actually shipped is expected to arrive.
        $inTransit = WarehouseTransfer::create([
            'transfer_number' => 'TRF-687-'.uniqid(),
            'from_warehouse_id' => $this->otherWarehouse->id,
            'to_warehouse_id' => $this->warehouse->id,
            'status' => 'in_transit',
            'transfer_date' => now()->toDateString(),
            'expected_date' => now()->addDays(1)->toDateString(),
            'created_by' => $this->admin->id,
        ]);
        WarehouseTransferItem::create([
            'warehouse_transfer_id' => $inTransit->id,
            'product_id' => $this->productA->id,
            'quantity_requested' => 6,
            'quantity_sent' => 4,
            'quantity_received' => 0,
        ]);

        // A transfer heading to ANOTHER warehouse is not incoming supply here.
        $elsewhere = WarehouseTransfer::create([
            'transfer_number' => 'TRF-687-'.uniqid(),
            'from_warehouse_id' => $this->warehouse->id,
            'to_warehouse_id' => $this->otherWarehouse->id,
            'status' => 'pending',
            'transfer_date' => now()->toDateString(),
            'expected_date' => now()->addDays(2)->toDateString(),
            'created_by' => $this->admin->id,
        ]);
        WarehouseTransferItem::create([
            'warehouse_transfer_id' => $elsewhere->id,
            'product_id' => $this->productA->id,
            'quantity_requested' => 99,
            'quantity_sent' => 0,
            'quantity_received' => 0,
        ]);

        $response = $this->bulkCheck([
            ['product_id' => $this->productA->id, 'quantity' => 1],
        ]);

        $response->assertOk();

        $incoming = $response->json('lines.0.incoming');
        $this->assertCount(2, $incoming, 'Pending + in-transit transfers, not the outbound one');

        $byType = collect($incoming)->keyBy('source_id');
        $this->assertEqualsWithDelta(6.0, (float) $byType[$pending->id]['quantity'], 0.0001, 'Pending: requested − received');
        $this->assertSame($pendingExpected, $byType[$pending->id]['expected_date']);
        $this->assertEqualsWithDelta(4.0, (float) $byType[$inTransit->id]['quantity'], 0.0001, 'In transit: sent − received');
    }

    public function test_bulk_stock_check_discloses_when_no_incoming_data_exists(): void
    {
        $response = $this->bulkCheck([
            ['product_id' => $this->productA->id, 'quantity' => 1],
        ]);

        $response->assertOk();
        $response->assertJsonPath('lines.0.incoming', []);
        $response->assertJsonPath('lines.0.incoming_quantity', 0);

        $note = $response->json('incoming_note');
        $this->assertIsString($note, 'An empty incoming list must be disclosed, not silently omitted');
        $this->assertStringContainsString('No open purchase orders or inbound transfers', $note);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // The single-product #465 path exposes incoming too (line-editor chip)
    // ═══════════════════════════════════════════════════════════════════════

    public function test_single_stock_check_exposes_incoming_for_the_line_chip(): void
    {
        $expectedDate = now()->addDays(5)->toDateString();
        $po = $this->createPurchaseOrder($this->productA, 'approved', 8, 3, $expectedDate);

        $response = $this->getJson(
            '/api/v1/sales/delivery-orders/stock-check?'
            .http_build_query([
                'product_id' => $this->productA->id,
                'warehouse_id' => $this->warehouse->id,
            ])
        );

        $response->assertOk();
        $response->assertJson([
            'on_hand' => 10,
            'reserved' => 0,
            'available' => 10,
        ]);

        $incoming = $response->json('incoming');
        $this->assertCount(1, $incoming);
        $this->assertSame($po->po_number, $incoming[0]['reference']);
        $this->assertEqualsWithDelta(5.0, (float) $incoming[0]['quantity'], 0.0001);
        $this->assertSame($expectedDate, $incoming[0]['expected_date']);
    }
}
