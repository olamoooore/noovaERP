<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\DeliveryOrderReturn;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #461 (P1, DO9) — partial delivery completion and partial returns.
 *
 * `quantity_delivered` was fixed at DO creation and `completeDeliveryOrder`
 * shipped all of it, so a short shipment (7 of 10) could not be recorded; the
 * only correction was reversing the whole order, and `reverseDeliveryOrder`
 * is all-or-nothing, so a partial customer return was impossible.
 *
 * Acceptance rules driven through the real routes (router → middleware →
 * controller):
 *   • completion accepts actual delivered quantities per line; the remainder
 *     becomes a linked back-order DO (default policy) or a cancellation with a
 *     reason (`sales.delivery.short_close_policy` = cancel);
 *   • a partial return document restocks exactly what came back (same lot,
 *     serials returned) and the delivery order stays `done`;
 *   • an over-quantity completion / an over-return are refused (422);
 *   • full-quantity completion keeps its pre-#461 behaviour.
 */
class Issue461PartialDeliveryAndReturnsTest extends TenantTestCase
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
            'name' => 'Partial Delivery Tenant',
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

        Setting::setValue('sales.delivery_order.default_requires_approval', false);
        Setting::setValue('sales.delivery.short_close_policy', 'backorder');

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 1000000]);

        $this->warehouse = Warehouse::create([
            'name' => 'Partial Delivery Warehouse',
            'code' => 'PD-'.uniqid(),
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
    // Fixtures / helpers
    // ─────────────────────────────────────────────────────────────────────

    private function trackedProduct(string $name, string $prefix, float $cost = 10.0, float $price = 25.0): Product
    {
        $product = Product::factory()->create([
            'name' => $name,
            'sku' => $prefix.'-'.uniqid(),
            'track_inventory' => true,
            'cost' => $cost,
            'price' => $price,
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

    private function complete(int $deliveryOrderId, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrderId}/complete", $payload);
    }

    private function recordReturn(int $deliveryOrderId, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrderId}/returns", $payload);
    }

    private function stockLevelOf(Product $product): float
    {
        return (float) StockLevel::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('quantity');
    }

    private function cogsPosted(int $deliveryOrderId): float
    {
        $link = JournalEntryLink::query()
            ->where('source_document_type', 'delivery_order')
            ->where('source_document_id', $deliveryOrderId)
            ->where('relation_type', 'post')
            ->first();

        if (! $link) {
            return 0.0;
        }

        $entry = JournalEntry::findOrFail($link->journal_entry_id);

        return round((float) $entry->lines()->sum('debit'), 2);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 1 — short completion: 7 of 10 ships, 3 become a linked back-order
    // ═══════════════════════════════════════════════════════════════════════

    public function test_partial_completion_ships_actual_quantities_and_creates_a_backorder(): void
    {
        $product = $this->trackedProduct('Partial Widget', 'PW-461', 10.00, 25.00);

        $deliveryOrderId = $this->standaloneDeliveryOrder([[
            'line_type' => 'product',
            'product_id' => $product->id,
            'description' => 'Partial Widget',
            'quantity_delivered' => 10,
            'unit_price' => 25,
        ]]);

        $line = DeliveryOrderLine::where('delivery_order_id', $deliveryOrderId)->firstOrFail();

        $response = $this->complete($deliveryOrderId, [
            'lines' => [[
                'line_id' => $line->id,
                'quantity_delivered' => 7,
            ]],
        ]);
        $response->assertOk($response->getContent());

        $deliveryOrder = DeliveryOrder::with('lines')->findOrFail($deliveryOrderId);
        $this->assertSame('done', $deliveryOrder->status, 'A short delivery is still a completed delivery.');
        $this->assertSame(7.0, (float) $deliveryOrder->lines->first()->quantity_delivered,
            'The line records the ACTUAL delivered quantity.');
        $this->assertSame(3.0, (float) $deliveryOrder->lines->first()->quantity_backordered,
            'The remainder is recorded on the source line as back-ordered.');
        $this->assertSame(175.0, (float) $deliveryOrder->total, 'The document total follows the goods that shipped.');

        $this->assertSame(3.0, $this->stockLevelOf($product), 'Only 7 units leave the warehouse.');

        $issued = (float) StockMovement::query()
            ->where('reference_type', 'DeliveryOrder')
            ->where('reference_id', $deliveryOrderId)
            ->where('type', 'sale')
            ->sum('quantity');
        $this->assertSame(7.0, $issued, 'Stock/COGS post for the ACTUAL quantity, not the planned one.');
        $this->assertSame(70.0, $this->cogsPosted($deliveryOrderId), '7 x cost 10 = 70.00 COGS.');

        $backorder = DeliveryOrder::with('lines')->where('backorder_of_id', $deliveryOrderId)->first();
        $this->assertNotNull($backorder, 'The undelivered remainder becomes a linked back-order delivery order.');
        $this->assertSame('draft', $backorder->status);
        $this->assertSame((int) $this->customer->id, (int) $backorder->customer_id);
        $this->assertSame((int) $this->warehouse->id, (int) $backorder->warehouse_id);
        $this->assertSame($deliveryOrderId, (int) $backorder->backorder_of_id);
        $this->assertSame(3.0, (float) $backorder->lines->first()->quantity_delivered,
            'The back-order carries exactly the undelivered remainder.');
        $this->assertSame((int) $product->id, (int) $backorder->lines->first()->product_id);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 2 — cancel policy: the remainder is cancelled with a reason
    // ═══════════════════════════════════════════════════════════════════════

    public function test_cancel_short_close_policy_cancels_the_remainder_with_a_reason(): void
    {
        Setting::setValue('sales.delivery.short_close_policy', 'cancel');

        $product = $this->trackedProduct('Cancelled Widget', 'CW-461', 10.00, 25.00);

        $deliveryOrderId = $this->standaloneDeliveryOrder([[
            'line_type' => 'product',
            'product_id' => $product->id,
            'description' => 'Cancelled Widget',
            'quantity_delivered' => 10,
            'unit_price' => 25,
        ]]);

        $line = DeliveryOrderLine::where('delivery_order_id', $deliveryOrderId)->firstOrFail();

        $this->complete($deliveryOrderId, [
            'lines' => [[
                'line_id' => $line->id,
                'quantity_delivered' => 7,
            ]],
            'short_close_reason' => 'Customer accepted a short shipment',
        ])->assertOk();

        $deliveryOrder = DeliveryOrder::with('lines')->findOrFail($deliveryOrderId);

        $this->assertSame('done', $deliveryOrder->status);
        $this->assertSame(3.0, (float) $deliveryOrder->lines->first()->quantity_cancelled,
            'The remainder is cancelled on the line.');
        $this->assertSame(0.0, (float) $deliveryOrder->lines->first()->quantity_backordered);
        $this->assertSame('Customer accepted a short shipment', $deliveryOrder->short_close_reason,
            'The cancellation reason is recorded on the delivery order.');
        $this->assertNull(
            DeliveryOrder::where('backorder_of_id', $deliveryOrderId)->first(),
            'The cancel policy must not create a back-order.'
        );
        $this->assertSame(3.0, $this->stockLevelOf($product));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 3 — over-quantity completion is refused
    // ═══════════════════════════════════════════════════════════════════════

    public function test_completing_more_than_planned_is_refused_with_422(): void
    {
        $product = $this->trackedProduct('Over Widget', 'OW-461', 10.00, 25.00);

        $deliveryOrderId = $this->standaloneDeliveryOrder([[
            'line_type' => 'product',
            'product_id' => $product->id,
            'description' => 'Over Widget',
            'quantity_delivered' => 10,
            'unit_price' => 25,
        ]]);

        $line = DeliveryOrderLine::where('delivery_order_id', $deliveryOrderId)->firstOrFail();

        $this->complete($deliveryOrderId, [
            'lines' => [[
                'line_id' => $line->id,
                'quantity_delivered' => 11,
            ]],
        ])->assertStatus(422);

        $this->assertSame('confirmed', DeliveryOrder::find($deliveryOrderId)->status,
            'A refused completion must not change the status.');
        $this->assertSame(10.0, $this->stockLevelOf($product), 'A refused completion must not move stock.');
        $this->assertSame(10.0, (float) $line->fresh()->quantity_delivered);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 4 — full completion keeps the pre-#461 behaviour
    // ═══════════════════════════════════════════════════════════════════════

    public function test_full_completion_without_a_payload_is_unchanged(): void
    {
        $product = $this->trackedProduct('Full Widget', 'FW-461', 10.00, 25.00);

        $deliveryOrderId = $this->standaloneDeliveryOrder([[
            'line_type' => 'product',
            'product_id' => $product->id,
            'description' => 'Full Widget',
            'quantity_delivered' => 10,
            'unit_price' => 25,
        ]]);

        $this->complete($deliveryOrderId)->assertOk();

        $deliveryOrder = DeliveryOrder::with('lines')->findOrFail($deliveryOrderId);
        $this->assertSame('done', $deliveryOrder->status);
        $this->assertSame(10.0, (float) $deliveryOrder->lines->first()->quantity_delivered);
        $this->assertSame(0.0, (float) $deliveryOrder->lines->first()->quantity_backordered);
        $this->assertSame(0.0, $this->stockLevelOf($product));
        $this->assertSame(100.0, $this->cogsPosted($deliveryOrderId));
        $this->assertNull(DeliveryOrder::where('backorder_of_id', $deliveryOrderId)->first());
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 5 — partial return: restocks the same lot, returns serials, DO stays done
    // ═══════════════════════════════════════════════════════════════════════

    public function test_partial_return_restocks_the_same_lot_and_returns_serials(): void
    {
        $product = $this->trackedProduct('Returned Widget', 'RW-461', 10.00, 25.00);

        $batch = ProductBatch::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_number' => 'RW-461-A',
            'lot_number' => 'RW-461-A',
            'quantity' => 10,
            'quantity_reserved' => 0,
            'cost' => 10.00,
            'status' => ProductBatch::STATUS_AVAILABLE,
            'received_date' => now()->subMonth()->toDateString(),
            'expiry_date' => '2027-01-31',
        ]);

        $serials = collect(['RW-461-0001', 'RW-461-0002', 'RW-461-0003', 'RW-461-0004'])->map(fn (string $number) => ProductSerial::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'serial_number' => $number,
            'status' => ProductSerial::STATUS_AVAILABLE,
            'cost' => 10.00,
        ]));

        $deliveryOrderId = $this->standaloneDeliveryOrder([[
            'line_type' => 'product',
            'product_id' => $product->id,
            'description' => 'Returned Widget',
            'quantity_delivered' => 4,
            'unit_price' => 25,
            'lot_number' => 'RW-461-A',
            'batch_number' => 'RW-461-A',
            'product_batch_id' => $batch->id,
            'expiry_date' => '2027-01-31',
        ]]);

        // Route fixture: the standalone create route has no `serial_numbers`
        // validation rule, so attach the shipped serials before completing.
        DeliveryOrderLine::where('delivery_order_id', $deliveryOrderId)
            ->update(['serial_numbers' => $serials->pluck('serial_number')->all()]);

        $this->complete($deliveryOrderId)->assertOk();

        $this->assertSame(6.0, $this->stockLevelOf($product), '4 of 10 units shipped.');

        $line = DeliveryOrderLine::where('delivery_order_id', $deliveryOrderId)->firstOrFail();

        $response = $this->recordReturn($deliveryOrderId, [
            'reason' => 'Two units damaged in transit',
            'lines' => [[
                'delivery_order_line_id' => $line->id,
                'quantity' => 2,
                'reason' => 'Damaged',
                'lot_number' => 'RW-461-A',
                'batch_number' => 'RW-461-A',
                'expiry_date' => '2027-01-31',
                'serial_numbers' => ['RW-461-0001', 'RW-461-0002'],
            ]],
        ]);
        $response->assertCreated($response->getContent());

        $this->assertSame(8.0, $this->stockLevelOf($product), 'Exactly the 2 returned units are restocked.');
        $this->assertSame(8, (int) $batch->fresh()->quantity, 'The named lot layer is restored, not a different one.');

        $this->assertSame('done', DeliveryOrder::find($deliveryOrderId)->status,
            'A partial return must NOT flip the whole delivery order to reversed.');
        $this->assertSame(2.0, (float) $line->fresh()->quantity_returned,
            'The line tracks how much has been returned.');

        // The return document records the line, quantity and reason.
        $return = DeliveryOrderReturn::with('lines')->where('delivery_order_id', $deliveryOrderId)->firstOrFail();
        $this->assertSame('completed', $return->status);
        $this->assertSame(2.0, (float) $return->lines->first()->quantity);
        $this->assertSame('Damaged', $return->lines->first()->reason);
        $this->assertSame('RW-461-A', $return->lines->first()->lot_number);

        // The restock movement is linked to the return document.
        $movement = StockMovement::query()
            ->where('reference_type', 'DeliveryOrderReturn')
            ->where('reference_id', $return->id)
            ->where('type', 'return')
            ->firstOrFail();
        $this->assertSame(2.0, (float) $movement->quantity);
        $this->assertSame('RW-461-A', $movement->batch_number);
        $this->assertSame('2027-01-31', $movement->expiry_date?->toDateString());

        // The returned serials are sellable again.
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $serials[0]->fresh()->status);
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $serials[1]->fresh()->status);
        $this->assertSame(ProductSerial::STATUS_SOLD, $serials[2]->fresh()->status,
            'Serials that did not come back stay sold.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 6 — returning beyond what was delivered is refused
    // ═══════════════════════════════════════════════════════════════════════

    public function test_returning_beyond_the_delivered_quantity_is_refused_with_422(): void
    {
        $product = $this->trackedProduct('Over Return Widget', 'OR-461', 10.00, 25.00);

        $deliveryOrderId = $this->standaloneDeliveryOrder([[
            'line_type' => 'product',
            'product_id' => $product->id,
            'description' => 'Over Return Widget',
            'quantity_delivered' => 4,
            'unit_price' => 25,
        ]]);

        $this->complete($deliveryOrderId)->assertOk();

        $line = DeliveryOrderLine::where('delivery_order_id', $deliveryOrderId)->firstOrFail();

        $this->recordReturn($deliveryOrderId, [
            'lines' => [[
                'delivery_order_line_id' => $line->id,
                'quantity' => 2,
            ]],
        ])->assertCreated();

        $this->assertSame(8.0, $this->stockLevelOf($product));

        // 2 returned of 4 delivered leaves 2 returnable — 3 more must be refused.
        $refused = $this->recordReturn($deliveryOrderId, [
            'lines' => [[
                'delivery_order_line_id' => $line->id,
                'quantity' => 3,
            ]],
        ]);
        $refused->assertStatus(422);

        $this->assertSame(8.0, $this->stockLevelOf($product),
            'A refused return must not restock anything.');
        $this->assertSame(2.0, (float) $line->fresh()->quantity_returned);
        $this->assertSame(1, DeliveryOrderReturn::where('delivery_order_id', $deliveryOrderId)->count());
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 7 — optional credit-note draft for the returned value
    // ═══════════════════════════════════════════════════════════════════════

    public function test_return_with_flag_creates_a_credit_note_draft(): void
    {
        $product = $this->trackedProduct('Credit Widget', 'CR-461', 10.00, 25.00);

        $deliveryOrderId = $this->standaloneDeliveryOrder([[
            'line_type' => 'product',
            'product_id' => $product->id,
            'description' => 'Credit Widget',
            'quantity_delivered' => 4,
            'unit_price' => 25,
        ]]);

        $this->complete($deliveryOrderId)->assertOk();

        // A credit note reverses an invoice (`customer_credit_notes.invoice_id`
        // is NOT NULL), so the scenario invoices the delivery first. An
        // uninvoiced return still restocks — it just skips the optional draft.
        $invoiceId = $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrderId}/create-invoice")
            ->assertStatus(201)
            ->json('data.id');
        $this->assertNotNull($invoiceId, 'The credit-note draft reverses the delivery invoice.');

        $line = DeliveryOrderLine::where('delivery_order_id', $deliveryOrderId)->firstOrFail();

        $response = $this->recordReturn($deliveryOrderId, [
            'create_credit_note_draft' => true,
            'lines' => [[
                'delivery_order_line_id' => $line->id,
                'quantity' => 2,
                'reason' => 'Wrong size',
            ]],
        ]);
        $response->assertCreated($response->getContent());

        $return = DeliveryOrderReturn::where('delivery_order_id', $deliveryOrderId)->firstOrFail();
        $this->assertNotNull($return->credit_note_id, 'The return links its draft credit note.');

        $creditNote = \App\Models\CustomerCreditNote::findOrFail($return->credit_note_id);
        $this->assertSame('draft', $creditNote->status, 'The credit note is a DRAFT — never posted by the return.');
        $this->assertSame((int) $this->customer->id, (int) $creditNote->customer_id);
        $this->assertSame((int) $invoiceId, (int) $creditNote->invoice_id,
            'The draft reverses the delivery order\'s invoice.');
        $this->assertSame(50.0, (float) $creditNote->total_amount, '2 returned x 25.00 = 50.00.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 8 — returns are only for completed deliveries
    // ═══════════════════════════════════════════════════════════════════════

    public function test_return_on_a_non_completed_delivery_is_refused(): void
    {
        $product = $this->trackedProduct('Not Done Widget', 'ND-461', 10.00, 25.00);

        $deliveryOrderId = $this->standaloneDeliveryOrder([[
            'line_type' => 'product',
            'product_id' => $product->id,
            'description' => 'Not Done Widget',
            'quantity_delivered' => 4,
            'unit_price' => 25,
        ]]);

        $line = DeliveryOrderLine::where('delivery_order_id', $deliveryOrderId)->firstOrFail();

        $this->recordReturn($deliveryOrderId, [
            'lines' => [[
                'delivery_order_line_id' => $line->id,
                'quantity' => 1,
            ]],
        ])->assertStatus(422);

        $this->assertSame(10.0, $this->stockLevelOf($product));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 9 — goods returned before billing are not invoiced afterwards
    // ═══════════════════════════════════════════════════════════════════════

    public function test_returned_goods_are_not_invoiced_afterwards(): void
    {
        $product = $this->trackedProduct('Unbilled Return Widget', 'UB-461', 10.00, 25.00);

        $deliveryOrderId = $this->standaloneDeliveryOrder([[
            'line_type' => 'product',
            'product_id' => $product->id,
            'description' => 'Unbilled Return Widget',
            'quantity_delivered' => 4,
            'unit_price' => 25,
        ]]);

        $this->complete($deliveryOrderId)->assertOk();

        $line = DeliveryOrderLine::where('delivery_order_id', $deliveryOrderId)->firstOrFail();

        $this->recordReturn($deliveryOrderId, [
            'lines' => [[
                'delivery_order_line_id' => $line->id,
                'quantity' => 2,
            ]],
        ])->assertCreated();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrderId}/create-invoice");
        $response->assertStatus(201, $response->getContent());

        $invoice = \App\Models\Invoice::with('lines')->findOrFail($response->json('data.id'));
        $this->assertSame(2.0, (float) $invoice->lines->sum('quantity'),
            'Only the 2 units that stayed with the customer are billed.');
        $this->assertSame(50.0, (float) $invoice->total, '2 x 25.00 = 50.00.');
    }
}
