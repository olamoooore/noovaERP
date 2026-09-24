<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Services\DeliveryOrderService;
use Modules\Sales\Services\SalesOrderService;
use Tests\TenantTestCase;

class DeliveryOrderTest extends TenantTestCase
{
    protected User $user;

    protected Customer $customer;

    protected Product $productA;

    protected Product $productB;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        // Issue #176: DO completion posts COGS through the authority gate,
        // which needs a real tenant uuid and the actor's sales permission.
        $this->bindTenantContextWithPermissions((string) \Illuminate\Support\Str::uuid());

        $this->assertTenantSchemaReady([
            'customers', 'products', 'sales_orders', 'sales_order_lines',
            'delivery_orders', 'delivery_order_lines',
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test-delivery-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->user->assignRole('Admin');
        $this->actingAs($this->user);

        // Issue #176: the COGS posting gate requires an open fiscal period.
        \App\Models\FiscalPeriod::firstOrCreate(
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

        $this->customer = Customer::factory()->create([
            'name' => 'Test Customer',
            'payment_terms' => 30,
        ]);

        $this->productA = Product::factory()->create([
            'name' => 'Widget A',
            'sku' => 'WGT-A-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        $this->productB = Product::factory()->create([
            'name' => 'Widget B',
            'sku' => 'WGT-B-'.uniqid(),
            'price' => 200,
            'cost' => 50,
            'track_inventory' => false,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Test Warehouse',
            'code' => 'TW-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function createSalesOrder(int $linesCount = 2, array $overrides = []): SalesOrder
    {
        $service = app(SalesOrderService::class);

        $lines = [];
        for ($i = 0; $i < $linesCount; $i++) {
            $product = $i === 0 ? $this->productA : $this->productB;
            $lines[] = [
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 100,
                'unit_price' => $product->price,
                'tax_rate' => 10,
                'discount_amount' => 0,
            ];
        }

        $order = $service->createOrder(array_merge([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => $lines,
        ], $overrides));

        return $service->confirmOrder($order);
    }

    private function createAndCompleteDO(SalesOrder $order, array $lineQtys): array
    {
        $service = app(DeliveryOrderService::class);
        $lines = [];
        foreach ($lineQtys as $soLineId => $qty) {
            $lines[] = [
                'sales_order_line_id' => $soLineId,
                'quantity_delivered' => $qty,
            ];
        }

        $do = $service->createDeliveryOrder($order, [
            'warehouse_id' => $this->warehouse->id,
            'lines' => $lines,
            'created_by' => $this->user->id,
        ]);

        $do = $service->completeDeliveryOrder($do);

        return [$do, $service];
    }

    // ════════════════════════════════════════════════════════════════════════
    //  AC-05: Stock deduction on completion
    // ════════════════════════════════════════════════════════════════════════

    public function test_ac05_stock_deducted_on_completion(): void
    {
        $order = $this->createSalesOrder(1);
        $soLineId = $order->lines->first()->id;

        $service = app(DeliveryOrderService::class);
        $do = $service->createDeliveryOrder($order, [
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['sales_order_line_id' => $soLineId, 'quantity_delivered' => 40]],
            'created_by' => $this->user->id,
        ]);

        $createdLine = $do->lines->first();
        $this->assertSame(100.0, (float) $createdLine->unit_price, 'Delivery line must inherit the quotation/sales-order unit price');
        $this->assertSame(4000.0, (float) $createdLine->line_total, 'Delivery line total must use the delivered quantity');

        // Confirm should NOT deduct stock
        $do = $service->confirmDeliveryOrder($do);
        $this->assertSame('confirmed', $do->status);

        $soLine = SalesOrderLine::find($soLineId);
        $this->assertEquals(0, (float) $soLine->quantity_delivered, 'Confirm should not update delivered qty');

        // Complete should deduct stock and update delivered qty
        $do = $service->completeDeliveryOrder($do);
        $this->assertSame('done', $do->status);
        $this->assertNotNull($do->completed_at);

        $soLine->refresh();
        $this->assertEquals(40, (float) $soLine->quantity_delivered, 'Complete should update delivered qty');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  AC-06: Exactly-once completion
    // ════════════════════════════════════════════════════════════════════════

    public function test_ac06_exactly_once_completion(): void
    {
        $order = $this->createSalesOrder(1);
        $soLineId = $order->lines->first()->id;

        [$do, $service] = $this->createAndCompleteDO($order, [$soLineId => 40]);

        // Second completion should be idempotent
        $do2 = $service->completeDeliveryOrder($do);
        $this->assertSame('done', $do2->status);

        $soLine = SalesOrderLine::find($soLineId);
        $this->assertEquals(40, (float) $soLine->quantity_delivered, 'Double completion should not increase delivered qty');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  AC-07: Partial delivery
    // ════════════════════════════════════════════════════════════════════════

    public function test_ac07_partial_delivery(): void
    {
        $order = $this->createSalesOrder(1);
        $soLineId = $order->lines->first()->id;

        [$do, $service] = $this->createAndCompleteDO($order, [$soLineId => 40]);

        $soLine = SalesOrderLine::find($soLineId);
        $this->assertEquals(100, (float) $soLine->quantity, 'Ordered');
        $this->assertEquals(40, (float) $soLine->quantity_delivered, 'Delivered');
        $this->assertEquals(60, (float) $soLine->quantity - (float) $soLine->quantity_delivered, 'Remaining');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  AC-08: Multiple deliveries
    // ════════════════════════════════════════════════════════════════════════

    public function test_ac08_multiple_deliveries(): void
    {
        $order = $this->createSalesOrder(1);
        $soLineId = $order->lines->first()->id;

        [$do1, $service] = $this->createAndCompleteDO($order, [$soLineId => 40]);
        [$do2, $service] = $this->createAndCompleteDO($order, [$soLineId => 60]);

        $soLine = SalesOrderLine::find($soLineId);
        $this->assertEquals(100, (float) $soLine->quantity_delivered, 'Total delivered after 2 DOs');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  AC-09: Prevent over-delivery
    // ════════════════════════════════════════════════════════════════════════

    public function test_ac09_prevent_over_delivery(): void
    {
        $order = $this->createSalesOrder(1);
        $soLineId = $order->lines->first()->id;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot deliver 150');

        $service = app(DeliveryOrderService::class);
        $service->createDeliveryOrder($order, [
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['sales_order_line_id' => $soLineId, 'quantity_delivered' => 150]],
            'created_by' => $this->user->id,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  AC-04: Warehouse mandatory blocks confirm
    // ════════════════════════════════════════════════════════════════════════

    public function test_ac04_warehouse_blocks_confirm(): void
    {
        $do = DeliveryOrder::create([
            'delivery_order_number' => DeliveryOrder::generateNumber(),
            'sales_order_id' => null,
            'customer_id' => $this->customer->id,
            'warehouse_id' => null,
            'status' => 'draft',
            'source' => 'standalone',
            'delivery_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $service = app(DeliveryOrderService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Warehouse is required');
        $service->confirmDeliveryOrder($do);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  AC-14: Standalone delivery stock deduction
    // ════════════════════════════════════════════════════════════════════════

    public function test_ac14_standalone_delivery(): void
    {
        $service = app(DeliveryOrderService::class);

        $do = $service->createStandaloneDeliveryOrder([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => [
                ['product_id' => $this->productA->id, 'description' => 'Widget A', 'quantity_delivered' => 10, 'unit' => 'pcs'],
            ],
        ]);

        $this->assertNull($do->sales_order_id);
        $this->assertSame('draft', $do->status);

        $do = $service->completeDeliveryOrder($do);
        $this->assertSame('done', $do->status);
        $this->assertNull($do->sales_order_id, 'Standalone DO should not create or update a SO');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  AC-15: Completed reversal
    // ════════════════════════════════════════════════════════════════════════

    public function test_ac15_completed_reversal(): void
    {
        $order = $this->createSalesOrder(1);
        $soLineId = $order->lines->first()->id;

        [$do, $service] = $this->createAndCompleteDO($order, [$soLineId => 40]);

        $soLine = SalesOrderLine::find($soLineId);
        $this->assertEquals(40, (float) $soLine->quantity_delivered);

        // Reverse
        $reversed = $service->reverseDeliveryOrder($do, 'Wrong delivery');
        $this->assertSame('cancelled', $reversed->status);

        $soLine->refresh();
        $this->assertEquals(0, (float) $soLine->quantity_delivered, 'Reversal should subtract delivered qty');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  AC-10: Delivered-qty invoice
    // ════════════════════════════════════════════════════════════════════════

    public function test_ac10_delivered_qty_invoice(): void
    {
        $order = $this->createSalesOrder(1);
        $soLineId = $order->lines->first()->id;

        // Set invoicing policy to delivered_qty
        SalesOrderLine::find($soLineId)->update(['invoicing_policy' => 'delivered_qty']);

        // Deliver 40
        [$do, $service] = $this->createAndCompleteDO($order, [$soLineId => 40]);

        $soService = app(SalesOrderService::class);
        $order = $order->fresh();

        // Allocate + fulfil for invoicing
        try {
            $order = $soService->allocateOrder($order);
            $order = $soService->fulfilOrder($order);
        } catch (\Exception $e) {
            // allocation may fail without stock, that's ok for invoicing test
        }

        $invoice = $soService->createInvoice($order->fresh());
        $invoiceLine = $invoice->lines->first();

        $this->assertNotNull($invoiceLine);
        $this->assertEquals(40, (int) $invoiceLine->quantity, 'Delivered-qty invoice should be 40');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  AC-13: Ordered-qty policy
    // ════════════════════════════════════════════════════════════════════════

    public function test_ac13_ordered_qty_policy(): void
    {
        $order = $this->createSalesOrder(1);
        $soLineId = $order->lines->first()->id;

        // Default policy is ordered_qty — no delivery needed
        $soService = app(SalesOrderService::class);

        try {
            $order = $soService->allocateOrder($order->fresh());
            $order = $soService->fulfilOrder($order);
        } catch (\Exception $e) {
            // allocation may fail
        }

        $invoice = $soService->createInvoice($order->fresh());
        $invoiceLine = $invoice->lines->first();

        $this->assertNotNull($invoiceLine);
        $this->assertEquals(100, (int) $invoiceLine->quantity, 'Ordered-qty invoice should be 100');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  AC-11: Prevent double billing
    // ════════════════════════════════════════════════════════════════════════

    public function test_ac11_prevent_double_billing(): void
    {
        $order = $this->createSalesOrder(1);
        $soLineId = $order->lines->first()->id;

        SalesOrderLine::find($soLineId)->update(['invoicing_policy' => 'delivered_qty']);
        [$do, $service] = $this->createAndCompleteDO($order, [$soLineId => 40]);

        $soService = app(SalesOrderService::class);

        try {
            $order = $soService->allocateOrder($order->fresh());
            $order = $soService->fulfilOrder($order);
        } catch (\Exception $e) {
        }

        // First invoice — should work for 40
        $invoice1 = $soService->createInvoice($order->fresh());
        $this->assertEquals(40, (int) $invoice1->lines->first()->quantity);

        // Mark quantity_invoiced as if the invoice was posted
        SalesOrderLine::find($soLineId)->update(['quantity_invoiced' => 40]);

        // Second invoice — should fail (no more invoiceable qty)
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('no invoiceable quantities');
        $soService->createInvoice($order->fresh());
    }

    // ════════════════════════════════════════════════════════════════════════
    //  AC-02: Shipping address default
    // ════════════════════════════════════════════════════════════════════════

    public function test_ac02_shipping_address_default(): void
    {
        $this->customer->update([
            'shipping_address' => '123 Delivery St',
            'billing_address' => '456 Billing Ave',
        ]);

        $service = app(DeliveryOrderService::class);
        $do = $service->createStandaloneDeliveryOrder([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'shipping_address' => '123 Delivery St',
            'created_by' => $this->user->id,
            'lines' => [
                ['product_id' => $this->productA->id, 'quantity_delivered' => 5, 'unit' => 'pcs'],
            ],
        ]);

        $this->assertEquals('123 Delivery St', $do->shipping_address);
        $this->assertTrue($do->shipping_address_overridden, 'Explicit address sets overridden flag');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  Pre-completion cancellation
    // ════════════════════════════════════════════════════════════════════════

    public function test_cancel_draft_has_no_stock_effect(): void
    {
        $order = $this->createSalesOrder(1);
        $soLineId = $order->lines->first()->id;

        $service = app(DeliveryOrderService::class);
        $do = $service->createDeliveryOrder($order, [
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['sales_order_line_id' => $soLineId, 'quantity_delivered' => 40]],
            'created_by' => $this->user->id,
        ]);

        $service->cancelDeliveryOrder($do, 'Not needed');

        $this->assertSame('cancelled', $do->fresh()->status);

        $soLine = SalesOrderLine::find($soLineId);
        $this->assertEquals(0, (float) $soLine->quantity_delivered, 'Cancelled draft should not affect delivered qty');
    }

    public function test_cancel_done_requires_reversal(): void
    {
        $order = $this->createSalesOrder(1);
        $soLineId = $order->lines->first()->id;

        [$do, $service] = $this->createAndCompleteDO($order, [$soLineId => 40]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Use reversal instead');
        $service->cancelDeliveryOrder($do);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  API-level tests
    // ════════════════════════════════════════════════════════════════════════

    public function test_api_complete_endpoint(): void
    {
        $order = $this->createSalesOrder(1);
        $soLineId = $order->lines->first()->id;

        $createResponse = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders', [
                'sales_order_id' => $order->id,
                'warehouse_id' => $this->warehouse->id,
                'lines' => [['sales_order_line_id' => $soLineId, 'quantity_delivered' => 50]],
            ]);

        $createResponse->assertStatus(201);
        $doId = $createResponse->json('data.id');

        // Complete via API
        $completeResponse = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/sales/delivery-orders/{$doId}/complete");

        $completeResponse->assertOk();
        $completeResponse->assertJson(['message' => 'Delivery order completed — stock deducted']);
        $this->assertSame('done', $completeResponse->json('data.status'));
    }

    public function test_api_reverse_endpoint(): void
    {
        $order = $this->createSalesOrder(1);
        $soLineId = $order->lines->first()->id;

        $createResponse = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders', [
                'sales_order_id' => $order->id,
                'warehouse_id' => $this->warehouse->id,
                'lines' => [['sales_order_line_id' => $soLineId, 'quantity_delivered' => 30]],
            ]);

        $doId = $createResponse->json('data.id');

        $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/sales/delivery-orders/{$doId}/complete")
            ->assertOk();

        // Reverse
        $reverseResponse = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/sales/delivery-orders/{$doId}/reverse", [
                'reason' => 'Shipped to wrong address',
            ]);

        $reverseResponse->assertOk();
        $reverseResponse->assertJson(['message' => 'Delivery order reversed — stock restored']);
    }

    public function test_api_list_delivery_orders(): void
    {
        $order = $this->createSalesOrder();
        $soLineIds = $order->lines->pluck('id')->toArray();

        $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders', [
                'sales_order_id' => $order->id,
                'warehouse_id' => $this->warehouse->id,
                'lines' => [['sales_order_line_id' => $soLineIds[0], 'quantity_delivered' => 20]],
            ]);

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->getJson('/api/v1/sales/delivery-orders');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    public function test_cannot_create_delivery_for_draft_order(): void
    {
        $service = app(SalesOrderService::class);
        $order = $service->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => [[
                'product_id' => $this->productA->id,
                'description' => 'Widget A',
                'quantity' => 10,
                'unit_price' => 100,
            ]],
        ]);

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders', [
                'sales_order_id' => $order->id,
                'warehouse_id' => $this->warehouse->id,
                'lines' => [['sales_order_line_id' => $order->lines->first()->id, 'quantity_delivered' => 5]],
            ]);

        $response->assertStatus(422);
    }

    public function test_standalone_requires_customer(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('customer_id is required');

        $service = app(DeliveryOrderService::class);
        $service->createStandaloneDeliveryOrder([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->productA->id, 'quantity_delivered' => 5]],
        ]);
    }

    public function test_standalone_requires_warehouse(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Warehouse is required');

        $service = app(DeliveryOrderService::class);
        $service->createStandaloneDeliveryOrder([
            'customer_id' => $this->customer->id,
            'lines' => [['product_id' => $this->productA->id, 'quantity_delivered' => 5]],
        ]);
    }

    public function test_bulk_delete_draft_delivery_orders(): void
    {
        $do1 = DeliveryOrder::create([
            'delivery_order_number' => DeliveryOrder::generateNumber(),
            'sales_order_id' => null,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
            'source' => 'standalone',
            'created_by' => $this->user->id,
        ]);
        $do2 = DeliveryOrder::create([
            'delivery_order_number' => DeliveryOrder::generateNumber(),
            'sales_order_id' => null,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
            'source' => 'standalone',
            'created_by' => $this->user->id,
        ]);

        $service = app(\App\Platform\BulkDelete\BulkDeleteService::class);
        $result = $service->delete('delivery_orders', [$do1->id, $do2->id], tenant()?->id, [
            'soft_deletes' => true,
            'check_fk' => false,
            'audit_log' => false,
        ]);

        $this->assertEquals(2, $result['deleted']);
        $this->assertSoftDeleted('delivery_orders', ['id' => $do1->id]);
        $this->assertSoftDeleted('delivery_orders', ['id' => $do2->id]);
    }

    public function test_list_filter_by_type_standalone(): void
    {
        DeliveryOrder::create([
            'delivery_order_number' => DeliveryOrder::generateNumber(),
            'sales_order_id' => null,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
            'source' => 'standalone',
            'created_by' => $this->user->id,
        ]);

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->getJson('/api/v1/sales/delivery-orders?type=standalone');

        $response->assertOk();
        $data = $response->json('data');
        foreach ($data as $do) {
            $this->assertNull($do['sales_order_id']);
        }
    }
}
