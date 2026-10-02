<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\User;
use App\Models\Warehouse;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Services\DeliveryOrderService;
use Tests\TenantTestCase;

/**
 * Enterprise document conversion flows:
 *   - Quotation → Delivery Order
 *   - Invoice   → Delivery Order
 *   - Delivery Order → Invoice
 */
class DocumentConversionFlowsTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) \Illuminate\Support\Str::uuid());

        $this->assertTenantSchemaReady([
            'customers', 'products', 'quotations', 'quotation_lines',
            'invoices', 'invoice_lines', 'delivery_orders', 'delivery_order_lines',
        ]);

        $this->user = User::create([
            'name' => 'Conversion User',
            'email' => 'conversion-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->actingAs($this->user);

        $this->customer = Customer::factory()->create([
            'name' => 'Conversion Customer',
            'payment_terms' => 30,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Conversion Widget',
            'sku' => 'CONV-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Conversion Warehouse',
            'code' => 'CW-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function confirmedQuotation(): Quotation
    {
        $quotation = Quotation::create([
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => 'confirmed',
            'customer_reference' => 'PO-CONV-1',
            'created_by' => $this->user->id,
        ]);

        QuotationLine::create([
            'quotation_id' => $quotation->id,
            'line_type' => 'product',
            'sequence' => 0,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => 5,
            'unit_price' => 100,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'line_total' => 500,
        ]);

        return $quotation->fresh('lines');
    }

    private function draftInvoice(): Invoice
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'draft',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $invoice->lines()->create([
            'line_type' => 'product',
            'sequence' => 0,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => 4,
            'unit_price' => 100,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ]);

        return $invoice->fresh('lines');
    }

    private function standaloneDeliveryOrder(): DeliveryOrder
    {
        $service = app(DeliveryOrderService::class);

        return $service->createStandaloneDeliveryOrder([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'delivery_date' => now()->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 3,
                'quantity_delivered' => 3,
                'unit_price' => 100,
            ]],
        ]);
    }

    public function test_delivery_order_send_confirms_recipient_and_cc(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $deliveryOrder = $this->standaloneDeliveryOrder();

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/customer-link", [
                'send_email' => true,
                'recipient_email' => 'ap@customer.test',
                'cc' => 'ops@customer.test, finance@customer.test',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.delivery.recipient', 'ap@customer.test');

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\CustomerEmail::class, function ($mail) {
            return $mail->hasTo('ap@customer.test')
                && $mail->hasCc('ops@customer.test')
                && $mail->hasCc('finance@customer.test');
        });
    }

    public function test_quotation_creates_delivery_order(): void
    {
        $quotation = $this->confirmedQuotation();

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-delivery-order");

        $response->assertStatus(201);
        $response->assertJsonPath('data.quotation_id', $quotation->id);

        $this->assertDatabaseHas('delivery_orders', [
            'id' => $response->json('data.id'),
            'customer_id' => $this->customer->id,
            'quotation_id' => $quotation->id,
        ]);
        $this->assertDatabaseHas('delivery_order_lines', [
            'delivery_order_id' => $response->json('data.id'),
            'product_id' => $this->product->id,
        ]);
    }

    /**
     * Decision (#293, product owner, 2026-10): converting AUTO-RELEASES the
     * quotation — a draft quote can be delivered (and the DO sent for approval)
     * before the quote is completed. The delivery order is created FROM the
     * released Sales Order so quantities reconcile; nothing is marked delivered
     * on the order until the DO is completed.
     */
    public function test_draft_quotation_auto_releases_and_creates_linked_delivery_order(): void
    {
        $quotation = $this->confirmedQuotation();
        $quotation->update(['status' => 'draft', 'sales_order_id' => null]);

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-delivery-order");

        $response->assertStatus(201);

        // The quote was released and the DO is linked to the resulting order.
        $quotation->refresh();
        $this->assertNotNull($quotation->sales_order_id, 'Draft quote must be auto-released to a Sales Order.');
        $this->assertDatabaseHas('delivery_orders', [
            'id' => $response->json('data.id'),
            'sales_order_id' => $quotation->sales_order_id,
            'quotation_id' => $quotation->id,
        ]);

        // Nothing delivered on the order yet — only planned on the draft DO.
        $soLine = SalesOrderLine::where('sales_order_id', $quotation->sales_order_id)->first();
        $this->assertSame(0.0, (float) $soLine->quantity_delivered, 'DO creation must not pre-deliver the order line.');
    }

    public function test_invoice_creates_delivery_order(): void
    {
        $invoice = $this->draftInvoice();

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-order");

        $response->assertStatus(201);
        $response->assertJsonPath('data.invoice_id', $invoice->id);

        $this->assertDatabaseHas('delivery_orders', [
            'id' => $response->json('data.id'),
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
        ]);

        // The conversion is tracked in chatter on both documents.
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => 'App\Models\Invoice',
            'subject_id' => $invoice->id,
            'event' => 'delivery_order_created',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => 'Modules\Sales\Models\DeliveryOrder',
            'subject_id' => $response->json('data.id'),
            'event' => 'created',
        ]);

        // An invoice can be delivered across several delivery orders (split
        // shipments) — the second conversion is allowed and linked.
        $second = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-order");

        $second->assertStatus(201);
        $this->assertNotSame($response->json('data.id'), $second->json('data.id'));

        $this->assertDatabaseHas('invoice_delivery_orders', [
            'invoice_id' => $invoice->id,
            'delivery_order_id' => $response->json('data.id'),
        ]);
        $this->assertDatabaseHas('invoice_delivery_orders', [
            'invoice_id' => $invoice->id,
            'delivery_order_id' => $second->json('data.id'),
        ]);
        $this->assertSame(2, $invoice->fresh()->deliveryOrders()->count());
    }

    public function test_invoice_to_delivery_order_allows_backorder_when_stock_is_zero(): void
    {
        // Stock-tracked product with no stock on hand — the conversion must
        // still create the DO (goods may not be received yet) instead of 500ing.
        $tracked = Product::factory()->create([
            'name' => 'Backorder Device',
            'sku' => 'BO-'.uniqid(),
            'price' => 250,
            'cost' => 100,
            'track_inventory' => true,
        ]);

        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'draft',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->lines()->create([
            'line_type' => 'product',
            'sequence' => 0,
            'product_id' => $tracked->id,
            'description' => $tracked->name,
            'quantity' => 2,
            'unit_price' => 250,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ]);

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-order");

        $response->assertStatus(201);
        $this->assertDatabaseHas('delivery_order_lines', [
            'delivery_order_id' => $response->json('data.id'),
            'product_id' => $tracked->id,
        ]);
    }

    public function test_invoice_send_can_add_and_save_customer_email(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $customer = Customer::factory()->create([
            'name' => 'No Email Customer',
            'email' => null,
            'payment_terms' => 30,
        ]);
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'draft',
            'approval_status' => Invoice::APPROVAL_APPROVED,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice->lines()->create([
            'line_type' => 'product',
            'sequence' => 0,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ]);

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/invoices/{$invoice->id}/acceptance-link", [
                'send_email' => true,
                'document_type' => 'final_invoice',
                'recipient_email' => 'ap@noemail.test',
                'save_customer_email' => true,
            ]);

        $response->assertStatus(201);

        // The typed address is persisted on the customer when requested.
        $this->assertSame('ap@noemail.test', $customer->fresh()->email);

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\CustomerEmail::class, function ($mail) {
            return $mail->hasTo('ap@noemail.test');
        });
    }

    public function test_delivery_order_creates_invoice(): void
    {
        $deliveryOrder = $this->standaloneDeliveryOrder();

        $response = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/create-invoice");

        $response->assertStatus(201);
        $invoiceId = $response->json('data.id');

        $this->assertNotNull($invoiceId);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoiceId,
            'customer_id' => $this->customer->id,
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => $invoiceId,
            'product_id' => $this->product->id,
        ]);

        // The DO is linked to the created invoice (legacy pointer + pivot) and
        // the billed quantity is tracked on the line.
        $this->assertSame($invoiceId, $deliveryOrder->fresh()->invoice_id);
        $this->assertDatabaseHas('invoice_delivery_orders', [
            'invoice_id' => $invoiceId,
            'delivery_order_id' => $deliveryOrder->id,
        ]);
        $this->assertSame(
            3.0,
            (float) $deliveryOrder->lines()->first()->quantity_invoiced,
        );

        // Chatter records the conversion on both documents.
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => 'App\Models\Invoice',
            'subject_id' => $invoiceId,
            'event' => 'created',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => 'Modules\Sales\Models\DeliveryOrder',
            'subject_id' => $deliveryOrder->id,
            'event' => 'invoice_created',
        ]);

        // Everything delivered is billed, so a second invoice is refused until
        // more is delivered.
        $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/create-invoice")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nothing left to invoice on this delivery order');

        // Another staged delivery on the same DO creates a second invoice that
        // bills only the additional quantity.
        $deliveryOrder->lines()->first()->update(['quantity_delivered' => 5]);

        $second = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/create-invoice");

        $second->assertStatus(201);
        $secondInvoiceId = $second->json('data.id');
        $this->assertNotSame($invoiceId, $secondInvoiceId);

        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => $secondInvoiceId,
            'product_id' => $this->product->id,
            'quantity' => 2,
        ]);
        $this->assertSame(
            5.0,
            (float) $deliveryOrder->lines()->first()->quantity_invoiced,
        );

        // Both invoices are linked; the first stays the primary pointer.
        $this->assertSame(2, $deliveryOrder->fresh()->invoices()->count());
        $this->assertSame($invoiceId, $deliveryOrder->fresh()->invoice_id);
    }

    public function test_quotation_can_be_invoiced_in_stages(): void
    {
        $quotation = $this->confirmedQuotation();

        $order = SalesOrder::create([
            'sales_order_number' => 'SO-QT-'.uniqid(),
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'status' => 'confirmed',
            'accounting_status' => 'not_posted',
            'subtotal' => 500,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 500,
            'order_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $orderLine = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => 5,
            'quantity_delivered' => 2,
            'invoicing_policy' => 'delivered_qty',
            'unit_price' => 100,
            'line_total' => 500,
            'tax_rate' => 0,
        ]);

        $quotation->update(['sales_order_id' => $order->id]);

        // First stage: 2 delivered → first invoice bills 2.
        $first = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-invoice");

        $first->assertStatus(201);
        $firstInvoiceId = $first->json('data.id');
        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => $firstInvoiceId,
            'product_id' => $this->product->id,
            'quantity' => 2,
        ]);

        // Second stage: more delivered → the follow-up invoice bills the
        // remainder instead of re-running allocation/fulfilment and failing.
        $orderLine->update(['quantity_delivered' => 5]);

        $second = $this->withoutMiddleware()
            ->actingAs($this->user)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-invoice");

        $second->assertStatus(201);
        $secondInvoiceId = $second->json('data.id');
        $this->assertNotSame($firstInvoiceId, $secondInvoiceId);
        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => $secondInvoiceId,
            'product_id' => $this->product->id,
            'quantity' => 3,
        ]);

        // The quotation now shows both invoices, and the order line tracks the
        // full invoiced quantity.
        $this->assertSame(2, $quotation->fresh()->invoices()->count());
        $this->assertSame(5.0, (float) $orderLine->fresh()->quantity_invoiced);
    }
}
