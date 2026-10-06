<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\DeliveryOrderService;
use Modules\Sales\Services\SalesOrderService;
use Tests\TenantTestCase;

/**
 * Issue #578 (MED-UX3): the case's Deliver and Invoice actions were bare
 * quantity dialogs — they never created or linked a delivery order or an
 * invoice. The case must be able to:
 *
 *  - "Create DO" from its lines through DeliveryOrderService (the DO lines
 *    are linked back to the case lines),
 *  - "Link to DO line" when goods were already delivered / consigned (a
 *    candidate list of completed DO lines for the case),
 *  - "Create invoice" from the case's delivered, DO-backed lines through the
 *    shared delivery-order invoicing path (DO and order lines are marked
 *    invoiced, so neither can bill the same goods again),
 *  - show the linked DO(s) and invoice with their status on the case.
 */
class Issue578MedicalCaseCreateDeliveryOrderAndInvoiceTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    private Product $focProduct;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = (string) Str::uuid();
        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $tenantId,
            'name' => 'Issue 578 Tenant',
            'email' => 'issue578-'.uniqid().'@test.local',
            'plan' => 'standard',
        ]));
        $this->bindTenantContextWithPermissions($tenantId);
        $this->assertTenantSchemaReady([
            'medical_cases', 'medical_case_items', 'sales_order_lines',
            'delivery_orders', 'delivery_order_lines', 'invoices', 'invoice_lines',
        ]);

        $this->user = User::create([
            'name' => 'Issue578 User',
            'email' => 'issue578-user-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->boundTestTenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->user);

        $this->customer = Customer::factory()->create(['name' => 'Issue 578 Hospital']);
        $this->product = Product::factory()->create([
            'name' => 'Issue 578 Implant',
            'sku' => 'I578-'.uniqid(),
            'price' => 100,
            'cost' => 40,
            'track_inventory' => false,
        ]);
        $this->focProduct = Product::factory()->create([
            'name' => 'Issue 578 FOC Screw',
            'sku' => 'I578F-'.uniqid(),
            'price' => 20,
            'cost' => 5,
            'track_inventory' => false,
        ]);
        $this->warehouse = Warehouse::create([
            'name' => 'Issue 578 Warehouse',
            'code' => 'I578-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function confirmedOrder(): SalesOrder
    {
        $service = app(SalesOrderService::class);

        $order = $service->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'description' => $this->product->name,
                    'quantity' => 10,
                    'unit_price' => 100,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ],
                [
                    'product_id' => $this->focProduct->id,
                    'description' => $this->focProduct->name,
                    'quantity' => 5,
                    'unit_price' => 20,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ],
            ],
        ]);

        return $service->confirmOrder($order)->fresh('lines');
    }

    /**
     * @return array{0: MedicalCase, 1: MedicalCaseItem, 2: MedicalCaseItem, 3: SalesOrder}
     */
    private function allocatedCase(): array
    {
        $order = $this->confirmedOrder();
        $implantLine = $order->lines->firstWhere('product_id', $this->product->id);
        $screwLine = $order->lines->firstWhere('product_id', $this->focProduct->id);

        $case = MedicalCase::create([
            'customer_id' => $this->customer->id,
            'status' => 'allocated',
            'created_by' => $this->user->id,
        ]);

        $implant = MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $implantLine->id,
            'product_id' => $this->product->id,
            'allocated_qty' => 3,
            'delivered_qty' => 0,
            'unit_price' => 100,
        ]);

        $screw = MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $screwLine->id,
            'product_id' => $this->focProduct->id,
            'allocated_qty' => 2,
            'delivered_qty' => 0,
            'unit_price' => 0,
            'is_foc' => true,
        ]);

        return [$case, $implant, $screw, $order];
    }

    public function test_create_delivery_order_from_the_case_creates_a_do_and_links_the_case_lines(): void
    {
        [$case, $implant, $screw, $order] = $this->allocatedCase();

        $response = $this->postJson("/api/v1/medical-cases/{$case->id}/create-delivery-order");

        $response->assertStatus(201);

        $deliveryOrders = DeliveryOrder::query()->where('sales_order_id', $order->id)->get();
        $this->assertCount(1, $deliveryOrders, 'One DO is created for the case lines of the order');

        $deliveryOrder = $deliveryOrders->first()->load('lines');
        $this->assertSame((int) $this->customer->id, (int) $deliveryOrder->customer_id);

        $implantLine = $deliveryOrder->lines->firstWhere('product_id', $this->product->id);
        $screwLine = $deliveryOrder->lines->firstWhere('product_id', $this->focProduct->id);
        $this->assertNotNull($implantLine);
        $this->assertNotNull($screwLine);
        $this->assertSame(3.0, round((float) $implantLine->quantity_delivered, 4), 'DO quantity = the case line allocation');
        $this->assertSame(2.0, round((float) $screwLine->quantity_delivered, 4));

        // The case lines point at the new DO lines, but nothing is delivered
        // until the DO is completed and the delivery is recorded (#562).
        $this->assertSame((int) $implantLine->id, (int) $implant->fresh()->delivery_order_item_id);
        $this->assertSame((int) $screwLine->id, (int) $screw->fresh()->delivery_order_item_id);
        $this->assertSame(0.0, round((float) $implant->fresh()->delivered_qty, 4));
        $this->assertSame('allocated', $case->fresh()->status);

        $response->assertJsonPath('data.delivery_orders.0.id', $deliveryOrder->id);
    }

    public function test_create_delivery_order_twice_is_refused_while_the_first_is_open(): void
    {
        [$case] = $this->allocatedCase();

        $this->postJson("/api/v1/medical-cases/{$case->id}/create-delivery-order")->assertStatus(201);

        $this->postJson("/api/v1/medical-cases/{$case->id}/create-delivery-order")
            ->assertStatus(422);

        $this->assertSame(1, DeliveryOrder::query()->count());
    }

    public function test_create_delivery_order_is_refused_for_a_draft_case(): void
    {
        [$case] = $this->allocatedCase();
        DB::table('medical_cases')->where('id', $case->id)->update(['status' => 'draft']);

        $this->postJson("/api/v1/medical-cases/{$case->id}/create-delivery-order")
            ->assertStatus(422);

        $this->assertSame(0, DeliveryOrder::query()->count());
    }

    public function test_linkable_delivery_order_lines_lists_completed_lines_for_the_case_products(): void
    {
        [$case, $implant] = $this->allocatedCase();

        $this->postJson("/api/v1/medical-cases/{$case->id}/create-delivery-order")->assertStatus(201);
        $deliveryOrder = DeliveryOrder::query()->firstOrFail();

        // Not completed yet: nothing can back a delivery.
        $before = $this->getJson("/api/v1/medical-cases/{$case->id}/delivery-order-lines")->assertOk();
        $this->assertSame([], $before->json("data.{$implant->id}"));

        app(DeliveryOrderService::class)->completeDeliveryOrder($deliveryOrder->fresh());

        $after = $this->getJson("/api/v1/medical-cases/{$case->id}/delivery-order-lines")->assertOk();
        $candidates = $after->json("data.{$implant->id}");
        $this->assertCount(1, $candidates);
        $this->assertSame((int) $implant->fresh()->delivery_order_item_id, (int) $candidates[0]['id']);
        $this->assertSame(3.0, (float) $candidates[0]['remaining_qty']);
    }

    public function test_create_invoice_from_the_case_bills_the_delivered_lines_and_links_them(): void
    {
        [$case, $implant, $screw, $order] = $this->allocatedCase();

        $this->postJson("/api/v1/medical-cases/{$case->id}/create-delivery-order")->assertStatus(201);
        $deliveryOrder = DeliveryOrder::query()->firstOrFail();
        app(DeliveryOrderService::class)->completeDeliveryOrder($deliveryOrder->fresh());

        $implantDoLineId = (int) $implant->fresh()->delivery_order_item_id;
        $screwDoLineId = (int) $screw->fresh()->delivery_order_item_id;

        $this->postJson("/api/v1/medical-cases/{$case->id}/deliver", ['items' => [
            ['id' => $implant->id, 'delivered_qty' => 3, 'delivery_order_item_id' => $implantDoLineId],
            ['id' => $screw->id, 'delivered_qty' => 2, 'delivery_order_item_id' => $screwDoLineId],
        ]])->assertOk();
        $this->assertSame('delivered', $case->fresh()->status);

        $response = $this->postJson("/api/v1/medical-cases/{$case->id}/create-invoice");
        $response->assertStatus(201);

        $case->refresh();
        $this->assertNotNull($case->invoice_id, 'The created invoice is linked to the case');
        $invoice = Invoice::with('lines')->findOrFail($case->invoice_id);
        $this->assertSame((int) $this->customer->id, (int) $invoice->customer_id);

        // Only the chargeable line is billed — the FOC line is redeemed free
        // of charge and needs no invoice line (#573 fulfilment rule).
        $productLines = $invoice->lines->where('line_type', 'product')->values();
        $this->assertCount(1, $productLines);
        $this->assertSame((int) $this->product->id, (int) $productLines[0]->product_id);
        $this->assertSame(3.0, round((float) $productLines[0]->quantity, 4));
        $this->assertSame(300.0, round((float) $invoice->subtotal, 2));

        $implant->refresh();
        $this->assertSame((int) $productLines[0]->id, (int) $implant->invoice_item_id);
        $this->assertSame(3.0, round((float) $implant->invoiced_qty, 4));
        $this->assertNull($screw->fresh()->invoice_item_id);

        // Shared DO invoicing path: the DO and order lines are marked invoiced
        // so neither "Create Invoice" can bill the same goods again.
        $this->assertSame(3.0, round((float) DeliveryOrderLine::findOrFail($implantDoLineId)->quantity_invoiced, 4));
        $orderLine = $order->lines->firstWhere('product_id', $this->product->id)->fresh();
        $this->assertSame(3.0, round((float) $orderLine->quantity_invoiced, 4));
        $linkedInvoiceIds = $deliveryOrder->fresh()->invoices()->pluck('invoices.id')
            ->push($deliveryOrder->fresh()->invoice_id)
            ->map(fn ($id) => (int) $id)
            ->all();
        $this->assertContains((int) $invoice->id, $linkedInvoiceIds);

        // Fully delivered and invoiced → the case moves to invoiced (engine).
        $this->assertSame('invoiced', $case->status);

        // A second create-invoice has nothing left to bill.
        $this->postJson("/api/v1/medical-cases/{$case->id}/create-invoice")->assertStatus(422);
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_create_invoice_without_a_delivered_do_backed_line_is_refused(): void
    {
        [$case] = $this->allocatedCase();
        DB::table('medical_cases')->where('id', $case->id)->update(['status' => 'partially_delivered']);

        $this->postJson("/api/v1/medical-cases/{$case->id}/create-invoice")->assertStatus(422);

        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_case_show_exposes_linked_delivery_orders_and_invoice_with_status(): void
    {
        [$case] = $this->allocatedCase();

        $this->postJson("/api/v1/medical-cases/{$case->id}/create-delivery-order")->assertStatus(201);
        $deliveryOrder = DeliveryOrder::query()->firstOrFail();

        $show = $this->getJson("/api/v1/medical-cases/{$case->id}")->assertOk();

        $show->assertJsonPath('linked_delivery_orders.0.id', $deliveryOrder->id);
        $show->assertJsonPath('linked_delivery_orders.0.delivery_order_number', $deliveryOrder->delivery_order_number);
        $show->assertJsonPath('linked_delivery_orders.0.status', 'draft');
    }
}
