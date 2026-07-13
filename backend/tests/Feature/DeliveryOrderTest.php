<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Services\SalesOrderService;
use Tests\TenantTestCase;

class DeliveryOrderTest extends TenantTestCase
{
    protected User $user;
    protected Customer $customer;
    protected Product $productA;
    protected Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTenantSchemaReady([
            'customers',
            'products',
            'sales_orders',
            'sales_order_lines',
            'delivery_orders',
            'delivery_order_lines',
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test-delivery-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
        ]);

        $this->customer = Customer::factory()->create([
            'name' => 'Test Customer',
            'payment_terms' => 30,
        ]);

        $this->productA = Product::factory()->create([
            'name' => 'Widget A',
            'sku' => 'WGT-A-' . uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        $this->productB = Product::factory()->create([
            'name' => 'Widget B',
            'sku' => 'WGT-B-' . uniqid(),
            'price' => 200,
            'cost' => 50,
            'track_inventory' => false,
        ]);
    }

    private function createSalesOrder(int $linesCount = 2): SalesOrder
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

        $order = $service->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'created_by' => $this->user->id,
            'lines' => $lines,
        ]);

        return $service->confirmOrder($order);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test: Create + Confirm Delivery Order
    // ──────────────────────────────────────────────────────────────────────────

    public function test_create_and_confirm_delivery_order(): void
    {
        $order = $this->createSalesOrder();
        $soLineIds = $order->lines->pluck('id')->toArray();

        $payload = [
            'sales_order_id' => $order->id,
            'delivery_date' => '2026-07-15',
            'carrier' => 'DHL',
            'tracking_number' => 'DH-12345',
            'lines' => [
                [
                    'sales_order_line_id' => $soLineIds[0],
                    'quantity_delivered' => 40,
                ],
                [
                    'sales_order_line_id' => $soLineIds[1],
                    'quantity_delivered' => 60,
                ],
            ],
        ];

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders', $payload);

        $response->assertStatus(201);
        $response->assertJson(['message' => 'Delivery order created']);
        $doId = $response->json('data.id');

        $deliveryOrder = DeliveryOrder::with('lines')->find($doId);
        $this->assertNotNull($deliveryOrder);
        $this->assertSame('draft', $deliveryOrder->status);
        $this->assertCount(2, $deliveryOrder->lines);

        // Confirm
        $confirmResponse = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/sales/delivery-orders/{$doId}/confirm");

        $confirmResponse->assertOk();
        $confirmResponse->assertJson(['message' => 'Delivery order confirmed']);

        // Verify quantity_delivered updated on sales order lines
        $soLine0 = SalesOrderLine::find($soLineIds[0]);
        $soLine1 = SalesOrderLine::find($soLineIds[1]);

        $this->assertEquals(40, (float) $soLine0->quantity_delivered);
        $this->assertEquals(60, (float) $soLine1->quantity_delivered);

        // Verify delivery order is confirmed
        $deliveryOrder->refresh();
        $this->assertSame('confirmed', $deliveryOrder->status);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test: Partial Delivery (two delivery orders for same SO)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_partial_delivery_with_two_delivery_orders(): void
    {
        $order = $this->createSalesOrder(1);
        $soLineId = $order->lines->first()->id;

        // First delivery: 40 units
        $do1Response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders', [
                'sales_order_id' => $order->id,
                'delivery_date' => '2026-07-15',
                'lines' => [
                    ['sales_order_line_id' => $soLineId, 'quantity_delivered' => 40],
                ],
            ]);

        $do1Response->assertStatus(201);
        $do1Id = $do1Response->json('data.id');

        $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/sales/delivery-orders/{$do1Id}/confirm")
            ->assertOk();

        $soLine = SalesOrderLine::find($soLineId);
        $this->assertEquals(40, (float) $soLine->quantity_delivered);

        // Second delivery: 30 more units
        $do2Response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders', [
                'sales_order_id' => $order->id,
                'delivery_date' => '2026-07-20',
                'lines' => [
                    ['sales_order_line_id' => $soLineId, 'quantity_delivered' => 30],
                ],
            ]);

        $do2Response->assertStatus(201);
        $do2Id = $do2Response->json('data.id');

        $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/sales/delivery-orders/{$do2Id}/confirm")
            ->assertOk();

        $soLine->refresh();
        $this->assertEquals(70, (float) $soLine->quantity_delivered);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test: Cannot over-deliver
    // ──────────────────────────────────────────────────────────────────────────

    public function test_cannot_over_deliver(): void
    {
        $order = $this->createSalesOrder(1);
        $soLineId = $order->lines->first()->id;

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders', [
                'sales_order_id' => $order->id,
                'delivery_date' => '2026-07-15',
                'lines' => [
                    ['sales_order_line_id' => $soLineId, 'quantity_delivered' => 150],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Cannot deliver 150', $response->json('message'));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test: Bill by delivered quantity
    // ──────────────────────────────────────────────────────────────────────────

    public function test_bill_by_delivered_quantity_uses_delivered_qty(): void
    {
        $order = $this->createSalesOrder(1);
        $soLineId = $order->lines->first()->id;

        // Enable bill-by-delivered
        $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/sales/orders/{$order->id}/bill-by-delivered", [
                'bill_by_delivered_qty' => true,
            ])
            ->assertOk();

        // Deliver 40 of 100 units
        $doResponse = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders', [
                'sales_order_id' => $order->id,
                'delivery_date' => '2026-07-15',
                'lines' => [
                    ['sales_order_line_id' => $soLineId, 'quantity_delivered' => 40],
                ],
            ]);

        $doId = $doResponse->json('data.id');
        $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/sales/delivery-orders/{$doId}/confirm")
            ->assertOk();

        // Allocate + Fulfil the order (required before invoicing)
        $service = app(SalesOrderService::class);
        $order = $service->allocateOrder($order->fresh());
        $order = $service->fulfilOrder($order);

        // Create invoice — should bill for 40, not 100
        $invoice = $service->createInvoice($order);

        $this->assertNotNull($invoice);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'sales_order_id' => $order->id,
        ]);

        // Invoice line should reflect delivered quantity (40)
        $invoiceLine = $invoice->lines->first();
        $this->assertNotNull($invoiceLine);
        // When bill-by-delivered is on, quantity = quantity_delivered (40)
        // which as an integer equals 40
        $this->assertEquals(40, (int) $invoiceLine->quantity);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test: Bill by ordered quantity (default behavior)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_bill_by_ordered_quantity_uses_ordered_qty(): void
    {
        $order = $this->createSalesOrder(1);
        $soLineId = $order->lines->first()->id;

        // Deliver 40 of 100 units (default: bill-by-delivered is false)
        $doResponse = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders', [
                'sales_order_id' => $order->id,
                'delivery_date' => '2026-07-15',
                'lines' => [
                    ['sales_order_line_id' => $soLineId, 'quantity_delivered' => 40],
                ],
            ]);

        $doId = $doResponse->json('data.id');
        $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/sales/delivery-orders/{$doId}/confirm")
            ->assertOk();

        $service = app(SalesOrderService::class);
        $order = $service->allocateOrder($order->fresh());
        $order = $service->fulfilOrder($order);

        // Create invoice — should bill for 100 (ordered), not 40 (delivered)
        $invoice = $service->createInvoice($order);

        $invoiceLine = $invoice->lines->first();
        $this->assertNotNull($invoiceLine);
        $this->assertEquals(100, (int) $invoiceLine->quantity);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test: Delivery order listing
    // ──────────────────────────────────────────────────────────────────────────

    public function test_list_delivery_orders(): void
    {
        $order = $this->createSalesOrder();
        $soLineIds = $order->lines->pluck('id')->toArray();

        // Create two delivery orders
        $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders', [
                'sales_order_id' => $order->id,
                'lines' => [
                    ['sales_order_line_id' => $soLineIds[0], 'quantity_delivered' => 20],
                ],
            ]);

        $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders', [
                'sales_order_id' => $order->id,
                'lines' => [
                    ['sales_order_line_id' => $soLineIds[1], 'quantity_delivered' => 30],
                ],
            ]);

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->getJson('/api/v1/sales/delivery-orders');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(2, count($data));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test: Delivery status for sales order
    // ──────────────────────────────────────────────────────────────────────────

    public function test_delivery_status_for_sales_order(): void
    {
        $order = $this->createSalesOrder(1);
        $soLineId = $order->lines->first()->id;

        // Deliver 40
        $doResponse = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders', [
                'sales_order_id' => $order->id,
                'lines' => [
                    ['sales_order_line_id' => $soLineId, 'quantity_delivered' => 40],
                ],
            ]);

        $doId = $doResponse->json('data.id');
        $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/sales/delivery-orders/{$doId}/confirm")
            ->assertOk();

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->getJson("/api/v1/sales/orders/{$order->id}/deliveries");

        $response->assertOk();
        $lines = $response->json('lines');
        $this->assertCount(1, $lines);
        $this->assertEquals(100, $lines[0]['quantity_ordered']);
        $this->assertEquals(40, $lines[0]['quantity_delivered']);
        $this->assertEquals(60, $lines[0]['quantity_remaining']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test: Cannot create delivery order for non-confirmed SO
    // ──────────────────────────────────────────────────────────────────────────

    public function test_cannot_create_delivery_for_draft_order(): void
    {
        $service = app(SalesOrderService::class);
        $order = $service->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'created_by' => $this->user->id,
            'lines' => [
                [
                    'product_id' => $this->productA->id,
                    'description' => 'Widget A',
                    'quantity' => 10,
                    'unit_price' => 100,
                ],
            ],
        ]);

        // Order is still draft — should fail
        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson('/api/v1/sales/delivery-orders', [
                'sales_order_id' => $order->id,
                'lines' => [
                    ['sales_order_line_id' => $order->lines->first()->id, 'quantity_delivered' => 5],
                ],
            ]);

        $response->assertStatus(422);
    }
}
