<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TenantTestCase;

/**
 * Issue #688 (SALES-INV UX2): invoices did not show whether their goods were
 * delivered and delivery orders did not show whether they were invoiced, which
 * made the invoice-first / DO-later double-issue situation (#682) easy.
 *
 * Acceptance, driven through the real HTTP routes (router → middleware →
 * controller):
 *   • per line and header: "Delivered 6/10" on invoices, "Invoiced 10/10" on
 *     delivery orders, with links to the DOs / invoices;
 *   • list filters "invoiced not delivered" and "delivered not invoiced".
 *
 * Fixtures write the same link bookkeeping the real posting / completion
 * paths write (SO-line delivered/invoiced mirrors, DO-line quantity_invoiced,
 * invoice ↔ DO pointer), as the #602 suite does.
 */
class Issue688DeliveryInvoicingProgressTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Progress 688 Tenant');
        CompanySettings::query()->create(['base_currency' => 'USD']);

        $this->customer = Customer::factory()->create(['name' => '688 Customer']);
        $this->product = Product::factory()->create([
            'name' => '688 Widget',
            'sku' => '688-'.uniqid(),
            'price' => 100,
            'cost' => 40,
            'track_inventory' => false,
        ]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** @return array{0: SalesOrder, 1: SalesOrderLine} */
    private function salesOrder(float $quantity): array
    {
        $order = SalesOrder::create([
            'sales_order_number' => 'SO-688-'.uniqid(),
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'status' => 'confirmed',
            'accounting_status' => 'not_posted',
            'subtotal' => $quantity * 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $quantity * 100,
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'order_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
        ]);

        $line = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $this->product->id,
            'description' => '688 order line',
            'quantity' => $quantity,
            'quantity_cancelled' => 0,
            'quantity_delivered' => 0,
            'quantity_invoiced' => 0,
            'unit_price' => 100,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => $quantity * 100,
        ]);

        return [$order, $line];
    }

    private function deliveryOrder(?SalesOrder $order, string $status, ?int $invoiceId = null): DeliveryOrder
    {
        return DeliveryOrder::create([
            'delivery_order_number' => 'DO-688-'.uniqid(),
            'sales_order_id' => $order?->id,
            'invoice_id' => $invoiceId,
            'customer_id' => $this->customer->id,
            'status' => $status,
            'source' => $invoiceId ? 'invoice' : ($order ? 'sales_order' : 'standalone'),
            'delivery_date' => now()->toDateString(),
            'completed_at' => $status === 'done' ? now() : null,
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'created_by' => $this->admin->id,
        ]);
    }

    /** A completed delivery of an SO line, with the SO-line delivered mirror. */
    private function deliver(SalesOrderLine $line, float $quantity): DeliveryOrderLine
    {
        $deliveryOrder = $this->deliveryOrder(SalesOrder::findOrFail($line->sales_order_id), 'done');

        $deliveryLine = DeliveryOrderLine::create([
            'delivery_order_id' => $deliveryOrder->id,
            'sales_order_line_id' => $line->id,
            'product_id' => $line->product_id,
            'description' => '688 delivery line',
            'quantity_ordered' => (float) $line->quantity,
            'quantity_delivered' => $quantity,
            'quantity_invoiced' => 0,
            'unit_price' => 100,
            'line_total' => $quantity * 100,
        ]);

        $line->update(['quantity_delivered' => (float) $line->quantity_delivered + $quantity]);

        return $deliveryLine;
    }

    private function invoice(?SalesOrder $order = null): Invoice
    {
        return Invoice::create([
            'invoice_number' => 'INV-688-'.uniqid(),
            'customer_id' => $this->customer->id,
            'sales_order_id' => $order?->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'balance' => 0,
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'created_by' => $this->admin->id,
        ]);
    }

    private function invoiceLine(Invoice $invoice, float $quantity, ?SalesOrderLine $orderLine = null): InvoiceLine
    {
        return InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'sales_order_line_id' => $orderLine?->id,
            'line_type' => 'product',
            'product_id' => $this->product->id,
            'description' => '688 invoice line',
            'quantity' => $quantity,
            'unit_price' => 100,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'line_total' => $quantity * 100,
        ]);
    }

    /**
     * An SO invoice with the SO-line / DO-line invoiced bookkeeping
     * SalesOrderService writes (#455 synchronizeDeliveryOrderInvoicedQuantities).
     */
    private function invoiceOrderLine(SalesOrderLine $line, float $quantity): array
    {
        $invoice = $this->invoice(SalesOrder::findOrFail($line->sales_order_id));
        $invoiceLine = $this->invoiceLine($invoice, $quantity, $line);

        $line->update(['quantity_invoiced' => (float) $line->quantity_invoiced + $quantity]);

        $remaining = $quantity;
        foreach (DeliveryOrderLine::where('sales_order_line_id', $line->id)->orderBy('id')->get() as $deliveryLine) {
            $billable = (float) ($deliveryLine->quantity_delivered ?: $deliveryLine->quantity_ordered)
                - (float) $deliveryLine->quantity_invoiced;
            $allocate = min(max($billable, 0), $remaining);
            if ($allocate <= 0.0001) {
                continue;
            }
            $deliveryLine->update(['quantity_invoiced' => (float) $deliveryLine->quantity_invoiced + $allocate]);
            $remaining -= $allocate;
        }

        return [$invoice, $invoiceLine];
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────

    private function invoiceProgress(Invoice $invoice): array
    {
        $response = $this->actingAs($this->admin)->getJson("/api/v1/invoices/{$invoice->id}");
        $response->assertOk();

        return $response->json('delivery_progress') ?? [];
    }

    private function deliveryProgress(DeliveryOrder $deliveryOrder): array
    {
        $response = $this->actingAs($this->admin)->getJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}");
        $response->assertOk();

        return $response->json('invoicing_progress') ?? [];
    }

    /** @return array<int, int> */
    private function invoiceListIds(array $params = []): array
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/invoices?'.http_build_query(array_merge(['per_page' => 100], $params)));
        $response->assertOk();

        return array_map('intval', array_column($response->json('data'), 'id'));
    }

    /** @return array<int, int> */
    private function deliveryOrderListIds(array $params = []): array
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/sales/delivery-orders?'.http_build_query(array_merge(['per_page' => 100], $params)));
        $response->assertOk();

        return array_map('intval', array_column($response->json('data'), 'id'));
    }

    // ── Tests ─────────────────────────────────────────────────────────────

    /** SO 10, delivered 6, invoiced 10 → invoice "Delivered 6/10", DO "Invoiced 6/6". */
    public function test_invoice_shows_delivered_x_of_y_and_do_shows_invoiced_x_of_y_with_links(): void
    {
        [, $orderLine] = $this->salesOrder(10);
        $deliveryLine = $this->deliver($orderLine, 6);
        [$invoice, $invoiceLine] = $this->invoiceOrderLine($orderLine->fresh(), 10);
        $deliveryOrder = DeliveryOrder::findOrFail($deliveryLine->delivery_order_id);

        $invoiceProgress = $this->invoiceProgress($invoice);
        $this->assertSame('partially_delivered', $invoiceProgress['status']);
        $this->assertEquals(6, $invoiceProgress['delivered_quantity']);
        $this->assertEquals(10, $invoiceProgress['quantity']);
        $this->assertEquals(6, $invoiceProgress['lines'][(string) $invoiceLine->id]['delivered_quantity']);
        $this->assertEquals(10, $invoiceProgress['lines'][(string) $invoiceLine->id]['quantity']);
        $this->assertSame([$deliveryOrder->id], array_column($invoiceProgress['delivery_orders'], 'id'));
        $this->assertSame($deliveryOrder->delivery_order_number, $invoiceProgress['delivery_orders'][0]['delivery_order_number']);

        $doProgress = $this->deliveryProgress($deliveryOrder);
        $this->assertSame('fully_invoiced', $doProgress['status']);
        $this->assertEquals(6, $doProgress['invoiced_quantity']);
        $this->assertEquals(6, $doProgress['quantity']);
        $this->assertEquals(6, $doProgress['lines'][(string) $deliveryLine->id]['invoiced_quantity']);
        $this->assertSame([$invoice->id], array_column($doProgress['invoices'], 'id'));
        $this->assertSame($invoice->invoice_number, $doProgress['invoices'][0]['invoice_number']);
    }

    /** Two invoices for the same order line never both claim the 6 shipped units. */
    public function test_delivered_quantity_covers_invoices_first_in_first_out(): void
    {
        [, $orderLine] = $this->salesOrder(10);
        $this->deliver($orderLine, 6);
        [$first] = $this->invoiceOrderLine($orderLine->fresh(), 5);
        [$second] = $this->invoiceOrderLine($orderLine->fresh(), 5);

        $firstProgress = $this->invoiceProgress($first);
        $this->assertSame('fully_delivered', $firstProgress['status']);
        $this->assertEquals(5, $firstProgress['delivered_quantity']);

        $secondProgress = $this->invoiceProgress($second);
        $this->assertSame('partially_delivered', $secondProgress['status']);
        $this->assertEquals(1, $secondProgress['delivered_quantity']);
        $this->assertEquals(5, $secondProgress['quantity']);
    }

    /** Invoice-first on an SO: the DO raised later is already invoiced. */
    public function test_do_raised_after_the_order_was_invoiced_shows_as_invoiced(): void
    {
        [, $orderLine] = $this->salesOrder(10);
        [$invoice] = $this->invoiceOrderLine($orderLine, 10);

        $this->assertSame('not_delivered', $this->invoiceProgress($invoice)['status']);

        $deliveryLine = $this->deliver($orderLine->fresh(), 10);
        $deliveryOrder = DeliveryOrder::findOrFail($deliveryLine->delivery_order_id);

        $doProgress = $this->deliveryProgress($deliveryOrder);
        $this->assertSame('fully_invoiced', $doProgress['status']);
        $this->assertEquals(10, $doProgress['invoiced_quantity']);
        $this->assertSame([$invoice->id], array_column($doProgress['invoices'], 'id'));

        $this->assertSame('fully_delivered', $this->invoiceProgress($invoice)['status']);
        $this->assertNotContains($deliveryOrder->id, $this->deliveryOrderListIds(['progress_filter' => 'delivered_not_invoiced']));
        $this->assertNotContains($invoice->id, $this->invoiceListIds(['progress_filter' => 'invoiced_not_delivered']));
    }

    /** Standalone invoice → DO raised from it (no SO): paired through the DO's invoice pointer. */
    public function test_invoice_first_standalone_pair(): void
    {
        $invoice = $this->invoice();
        $invoiceLine = $this->invoiceLine($invoice, 10);
        $deliveryOrder = $this->deliveryOrder(null, 'draft', $invoice->id);
        $deliveryLine = DeliveryOrderLine::create([
            'delivery_order_id' => $deliveryOrder->id,
            'product_id' => $this->product->id,
            'description' => '688 delivery line',
            'quantity_ordered' => 10,
            'quantity_delivered' => 10,
            'quantity_invoiced' => 0,
            'unit_price' => 100,
            'line_total' => 1000,
        ]);

        $invoiceProgress = $this->invoiceProgress($invoice);
        $this->assertSame('not_delivered', $invoiceProgress['status']);
        $this->assertEquals(0, $invoiceProgress['lines'][(string) $invoiceLine->id]['delivered_quantity']);
        $this->assertSame([$deliveryOrder->id], array_column($invoiceProgress['delivery_orders'], 'id'));
        $this->assertContains($invoice->id, $this->invoiceListIds(['progress_filter' => 'invoiced_not_delivered']));

        $doProgress = $this->deliveryProgress($deliveryOrder);
        $this->assertSame('fully_invoiced', $doProgress['status']);
        $this->assertEquals(10, $doProgress['lines'][(string) $deliveryLine->id]['invoiced_quantity']);

        // Shipped 6 of the 10.
        $deliveryOrder->update(['status' => 'done', 'completed_at' => now()]);
        $deliveryLine->update(['quantity_delivered' => 6]);

        $invoiceProgress = $this->invoiceProgress($invoice);
        $this->assertSame('partially_delivered', $invoiceProgress['status']);
        $this->assertEquals(6, $invoiceProgress['delivered_quantity']);
        $this->assertEquals(10, $invoiceProgress['quantity']);
    }

    /** Standalone DO billed for 4 of 10 → "Invoiced 4/10", listed as delivered-not-invoiced. */
    public function test_partially_invoiced_delivery_is_listed_as_delivered_not_invoiced(): void
    {
        $deliveryOrder = $this->deliveryOrder(null, 'done');
        $deliveryLine = DeliveryOrderLine::create([
            'delivery_order_id' => $deliveryOrder->id,
            'product_id' => $this->product->id,
            'description' => '688 delivery line',
            'quantity_ordered' => 10,
            'quantity_delivered' => 10,
            'quantity_invoiced' => 0,
            'unit_price' => 100,
            'line_total' => 1000,
        ]);

        $this->assertSame('not_invoiced', $this->deliveryProgress($deliveryOrder)['status']);
        $this->assertContains($deliveryOrder->id, $this->deliveryOrderListIds(['progress_filter' => 'delivered_not_invoiced']));

        // DO → Invoice for 4: the controller increments quantity_invoiced and links the invoice.
        $invoice = $this->invoice();
        $this->invoiceLine($invoice, 4);
        $deliveryLine->update(['quantity_invoiced' => 4]);
        $deliveryOrder->update(['invoice_id' => $invoice->id]);

        $doProgress = $this->deliveryProgress($deliveryOrder);
        $this->assertSame('partially_invoiced', $doProgress['status']);
        $this->assertEquals(4, $doProgress['invoiced_quantity']);
        $this->assertEquals(10, $doProgress['quantity']);
        $this->assertContains($deliveryOrder->id, $this->deliveryOrderListIds(['progress_filter' => 'delivered_not_invoiced']));

        $this->assertSame('fully_delivered', $this->invoiceProgress($invoice)['status']);

        // Billing the remaining 6 takes it off the list.
        $deliveryLine->update(['quantity_invoiced' => 10]);
        $this->assertSame('fully_invoiced', $this->deliveryProgress($deliveryOrder)['status']);
        $this->assertNotContains($deliveryOrder->id, $this->deliveryOrderListIds(['progress_filter' => 'delivered_not_invoiced']));

        // A draft DO is not "delivered", whatever its invoicing.
        $draft = $this->deliveryOrder(null, 'draft');
        DeliveryOrderLine::create([
            'delivery_order_id' => $draft->id,
            'product_id' => $this->product->id,
            'quantity_ordered' => 3,
            'quantity_delivered' => 3,
            'quantity_invoiced' => 0,
        ]);
        $this->assertNotContains($draft->id, $this->deliveryOrderListIds(['progress_filter' => 'delivered_not_invoiced']));
    }

    /** A direct invoice with no order and no delivery has no delivery to track. */
    public function test_direct_invoice_without_links_is_not_tracked_and_not_filtered(): void
    {
        $invoice = $this->invoice();
        $this->invoiceLine($invoice, 3);

        $this->assertSame('not_tracked', $this->invoiceProgress($invoice)['status']);
        $this->assertNotContains($invoice->id, $this->invoiceListIds(['progress_filter' => 'invoiced_not_delivered']));
        $this->assertContains($invoice->id, $this->invoiceListIds());
    }

    /** List rows carry the chips, batch-loaded per page (no N+1). */
    public function test_list_endpoints_carry_progress_without_n_plus_one(): void
    {
        [, $orderLine] = $this->salesOrder(10);
        $deliveryLine = $this->deliver($orderLine, 6);
        [$invoice] = $this->invoiceOrderLine($orderLine->fresh(), 10);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/invoices?per_page=100');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $invoice->id);
        $this->assertSame('partially_delivered', $row['delivery_progress']['status']);
        $this->assertEquals(6, $row['delivery_progress']['delivered_quantity']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/sales/delivery-orders?per_page=100');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $deliveryLine->delivery_order_id);
        $this->assertSame('fully_invoiced', $row['invoicing_progress']['status']);

        $this->assertSame('partially_delivered', $this->invoiceProgress($invoice)['status']);

        // The progress chips must cost a fixed number of queries per page —
        // one for the line quantities, one for the linked documents. (Other
        // per-row serialization costs of the list are outside this issue.)
        $countProgressQueries = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->admin)->getJson($url)->assertOk();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            return collect($queries)->filter(fn (array $query) => str_contains($query['query'], 'x.invoice_line_id')
                || str_contains($query['query'], 'x.delivery_order_line_id')
                || str_contains($query['query'], 'SELECT DISTINCT link.'))->count();
        };

        for ($i = 0; $i < 3; $i++) {
            [, $line] = $this->salesOrder(4);
            $this->deliver($line, 2);
            $this->invoiceOrderLine($line->fresh(), 4);
        }

        $this->assertSame(2, $countProgressQueries('/api/v1/invoices?per_page=100'));
        $this->assertSame(2, $countProgressQueries('/api/v1/sales/delivery-orders?per_page=100'));
    }
}
