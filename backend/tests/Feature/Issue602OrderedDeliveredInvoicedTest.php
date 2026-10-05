<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosOrderItem;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TenantTestCase;

/**
 * Issue #602 (SALES-ODOO): the Sales Analysis pivot exposed one mixed
 * "revenue" measure. This suite drives the real HTTP route
 * (router → middleware → AnalyticsController → SalesAnalyticsService) and pins
 * the Odoo-style lifecycle measures the issue asks for:
 *
 *   - qty_ordered / amount_ordered      sales-order line ordered quantity
 *                                       (`quantity − quantity_cancelled`) at
 *                                       the order line's tax-excluded,
 *                                       header-discount-net value;
 *   - qty_delivered / amount_delivered  delivered quantity (mirrored onto the
 *                                       SO line by delivery completion);
 *   - qty_invoiced / amount_invoiced    posted invoice lines, credit notes
 *                                       netted (an invoiced SO counts through
 *                                       its invoice exactly once, #590);
 *   - qty_to_invoice / amount_to_invoice delivered − invoiced (NOT clamped:
 *                                       negative surfaces over-invoicing);
 *   - POS sales and invoices without a backing SO fall back to
 *     ordered = delivered = invoiced = their quantity (#590), so to-invoice is
 *     0 for an immediate sale.
 *
 * The amounts are the same #355 convention the other measures use: tax
 * excluded, document header discount net, converted to the tenant base
 * currency at the document's own snapshot rate.
 *
 * The "unbilled deliveries" endpoint is the DO-line view of the same rule:
 * completed delivery orders whose delivered quantity still exceeds what has
 * been invoiced, scoped by the #593 filter contract.
 */
class Issue602OrderedDeliveredInvoicedTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Sales Measures 602 Tenant');
        CompanySettings::query()->create(['base_currency' => 'USD']);

        $this->category = ProductCategory::create([
            'name' => '602 Widgets',
            'is_active' => true,
        ]);

        $this->customer = Customer::factory()->create(['name' => '602 Customer']);

        $this->product = Product::factory()->create([
            'name' => '602 Widget',
            'sku' => '602-'.uniqid(),
            'price' => 100,
            'cost' => 40,
            'track_inventory' => false,
            'category_id' => $this->category->id,
        ]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @return array{0: SalesOrder, 1: SalesOrderLine}
     */
    private function salesOrder(float $quantity, float $unitPrice, ?Customer $customer = null): array
    {
        $order = SalesOrder::create([
            'sales_order_number' => 'SO-602-'.uniqid(),
            'customer_id' => ($customer ?? $this->customer)->id,
            'channel' => 'admin',
            'status' => 'confirmed',
            'accounting_status' => 'not_posted',
            'subtotal' => $quantity * $unitPrice,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $quantity * $unitPrice,
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'order_date' => '2026-08-05',
            'created_by' => $this->admin->id,
        ]);

        $line = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $this->product->id,
            'description' => '602 order line',
            'quantity' => $quantity,
            'quantity_cancelled' => 0,
            'quantity_delivered' => 0,
            'quantity_invoiced' => 0,
            'unit_price' => $unitPrice,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => $quantity * $unitPrice,
        ]);

        return [$order, $line];
    }

    /**
     * A completed delivery order for a line and the SO-line mirror the real
     * DeliveryOrderService completion writes (`quantity_delivered`).
     */
    private function deliver(SalesOrderLine $line, float $quantity, string $date = '2026-08-06'): DeliveryOrderLine
    {
        $order = SalesOrder::findOrFail($line->sales_order_id);

        $deliveryOrder = DeliveryOrder::create([
            'delivery_order_number' => 'DO-602-'.uniqid(),
            'sales_order_id' => $line->sales_order_id,
            'customer_id' => $order->customer_id,
            'status' => 'done',
            'delivery_date' => $date,
            'completed_at' => $date.' 10:00:00',
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'created_by' => $this->admin->id,
        ]);

        $deliveryLine = DeliveryOrderLine::create([
            'delivery_order_id' => $deliveryOrder->id,
            'sales_order_line_id' => $line->id,
            'product_id' => $line->product_id,
            'description' => '602 delivery line',
            'quantity_ordered' => (float) $line->quantity,
            'quantity_delivered' => $quantity,
            'quantity_invoiced' => 0,
            'unit_price' => (float) $line->unit_price,
            'line_total' => $quantity * (float) $line->unit_price,
        ]);

        // Mirror of DeliveryOrderService::complete(): the SO line accumulates
        // what was delivered.
        $line->update([
            'quantity_delivered' => (float) $line->quantity_delivered + $quantity,
        ]);

        return $deliveryLine;
    }

    /**
     * A posted invoice for a line, with the same SO-line / DO-line invoiced
     * bookkeeping the real SalesOrderService writes after posting.
     */
    private function postInvoice(
        SalesOrderLine $line,
        float $quantity,
        float $unitPrice,
        string $date = '2026-08-07',
    ): Invoice {
        $order = SalesOrder::findOrFail($line->sales_order_id);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-602-'.uniqid(),
            'customer_id' => $order->customer_id,
            'sales_order_id' => $order->id,
            'invoice_date' => $date,
            'due_date' => $date,
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'subtotal' => $quantity * $unitPrice,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $quantity * $unitPrice,
            'balance' => $quantity * $unitPrice,
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'created_by' => $this->admin->id,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'sales_order_line_id' => $line->id,
            'line_type' => 'product',
            'product_id' => $line->product_id,
            'description' => '602 invoice line',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'line_total' => $quantity * $unitPrice,
        ]);

        $line->update([
            'quantity_invoiced' => (float) $line->quantity_invoiced + $quantity,
        ]);

        // Mirror of SalesOrderService::synchronizeDeliveryOrderInvoicedQuantities:
        // consume the DO line's billable quantity.
        $remaining = $quantity;
        foreach (DeliveryOrderLine::where('sales_order_line_id', $line->id)->orderBy('id')->get() as $deliveryLine) {
            if ($remaining <= 0.0001) {
                break;
            }
            $billable = (float) ($deliveryLine->quantity_delivered ?: $deliveryLine->quantity_ordered)
                - (float) $deliveryLine->quantity_invoiced;
            $allocate = min(max($billable, 0), $remaining);
            if ($allocate <= 0.0001) {
                continue;
            }
            $deliveryLine->update([
                'quantity_invoiced' => (float) $deliveryLine->quantity_invoiced + $allocate,
            ]);
            $remaining -= $allocate;
        }

        return $invoice;
    }

    /** A posted customer credit note (reduces invoiced quantities/amounts). */
    private function postCreditNote(
        Invoice $invoice,
        float $quantity,
        float $unitPrice,
        string $date = '2026-08-08',
    ): void {
        $note = \App\Models\CustomerCreditNote::create([
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => $date,
            'status' => 'posted',
            'subtotal' => $quantity * $unitPrice,
            'tax_amount' => 0,
            'total_amount' => $quantity * $unitPrice,
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'created_by' => $this->admin->id,
            'reason' => '602 test credit',
        ]);

        $note->lines()->create([
            'product_id' => $this->product->id,
            'description' => '602 credit line',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'line_total' => $quantity * $unitPrice,
        ]);
    }

    /** A direct posted invoice with no backing sales order (#590). */
    private function directInvoice(float $quantity, float $unitPrice): Invoice
    {
        $invoice = Invoice::create([
            'invoice_number' => 'INV-602-D-'.uniqid(),
            'customer_id' => $this->customer->id,
            'sales_order_id' => null,
            'invoice_date' => '2026-08-09',
            'due_date' => '2026-08-09',
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'subtotal' => $quantity * $unitPrice,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $quantity * $unitPrice,
            'balance' => $quantity * $unitPrice,
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'created_by' => $this->admin->id,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'line_type' => 'product',
            'product_id' => $this->product->id,
            'description' => '602 direct invoice line',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'line_total' => $quantity * $unitPrice,
        ]);

        return $invoice;
    }

    private function posSale(float $quantity, float $unitPrice): PosOrder
    {
        $order = PosOrder::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'paid',
            'is_return' => false,
            'subtotal' => $quantity * $unitPrice,
            'tax_amount' => 0,
            'total' => $quantity * $unitPrice,
            'currency' => 'USD',
            'paid_at' => Carbon::parse('2026-08-10 12:00:00'),
            'payment_method' => 'cash',
        ]);

        PosOrderItem::create([
            'pos_order_id' => $order->id,
            'product_id' => $this->product->id,
            'name' => $this->product->name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $quantity * $unitPrice,
        ]);

        return $order;
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function pivot(array $params = []): array
    {
        $query = array_merge([
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-30',
        ], $params);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/pivot?'.http_build_query($query));

        $response->assertOk();

        return $response->json('data') ?? [];
    }

    /** @return array<string, mixed> */
    private function unbilled(array $params = []): array
    {
        $query = array_merge([
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-30',
        ], $params);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/unbilled-deliveries?'.http_build_query($query));

        $response->assertOk();

        return $response->json('data') ?? [];
    }

    private const MEASURES = [
        'qty_ordered', 'qty_delivered', 'qty_invoiced', 'qty_to_invoice',
        'amount_ordered', 'amount_delivered', 'amount_invoiced', 'amount_to_invoice',
    ];

    // ── Ordered / delivered / invoiced / to-invoice measures ─────────────

    /**
     * Acceptance: SO ordered 10, partial delivery 6, invoice 4 →
     * qty 10/6/4/2 and amounts 1000/600/400/200.
     */
    public function test_pivot_reports_ordered_delivered_invoiced_and_to_invoice_measures(): void
    {
        [$order, $line] = $this->salesOrder(10, 100);
        $this->deliver($line, 6);
        $this->postInvoice($line, 4, 100);

        $data = $this->pivot([
            'row_dims' => ['product'],
            'measure' => self::MEASURES,
        ]);

        $totals = $data['totals'];

        $this->assertEquals(10, $totals['qty_ordered']);
        $this->assertEquals(6, $totals['qty_delivered']);
        $this->assertEquals(4, $totals['qty_invoiced']);
        $this->assertEquals(2, $totals['qty_to_invoice']);
        $this->assertEquals(1000, $totals['amount_ordered']);
        $this->assertEquals(600, $totals['amount_delivered']);
        $this->assertEquals(400, $totals['amount_invoiced']);
        $this->assertEquals(200, $totals['amount_to_invoice']);

        // The row carries the same values, and the existing revenue measure is
        // untouched: the invoiced SO still counts once, through its invoice.
        $row = collect($data['rows'])->firstWhere('label', '602 Widget');
        $this->assertNotNull($row, 'The pivot must keep a row for the product');
        $this->assertEquals(10, $row['values'][0]['qty_ordered']);
        $this->assertEquals(2, $row['values'][0]['qty_to_invoice']);
        $this->assertEquals(200, $row['values'][0]['amount_to_invoice']);

        $revenue = $this->pivot([
            'row_dims' => ['product'],
            'measure' => ['net_revenue'],
        ]);
        $this->assertEquals(400, $revenue['totals']['net_revenue'], 'Invoiced SO still counts once through the invoice');
    }

    public function test_credit_note_reduces_invoiced_quantities_and_amounts(): void
    {
        [$order, $line] = $this->salesOrder(10, 100);
        $this->deliver($line, 6);
        $invoice = $this->postInvoice($line, 4, 100);
        $this->postCreditNote($invoice, 1, 100);

        $totals = $this->pivot([
            'row_dims' => ['product'],
            'measure' => self::MEASURES,
        ])['totals'];

        $this->assertEquals(10, $totals['qty_ordered']);
        $this->assertEquals(6, $totals['qty_delivered']);
        $this->assertEquals(3, $totals['qty_invoiced'], '4 invoiced − 1 credited');
        $this->assertEquals(3, $totals['qty_to_invoice'], '6 delivered − 3 net invoiced');
        $this->assertEquals(1000, $totals['amount_ordered']);
        $this->assertEquals(600, $totals['amount_delivered']);
        $this->assertEquals(300, $totals['amount_invoiced']);
        $this->assertEquals(300, $totals['amount_to_invoice']);
    }

    /**
     * #590 rules: a POS sale and a direct invoice (no SO) are immediate sales
     * — ordered = delivered = invoiced = their quantity, to-invoice 0.
     */
    public function test_pos_and_direct_invoice_count_as_immediate_sales(): void
    {
        $this->posSale(2, 50);
        $this->directInvoice(3, 10);

        $totals = $this->pivot([
            'row_dims' => ['product'],
            'measure' => self::MEASURES,
        ])['totals'];

        $this->assertEquals(5, $totals['qty_ordered']);
        $this->assertEquals(5, $totals['qty_delivered']);
        $this->assertEquals(5, $totals['qty_invoiced']);
        $this->assertEquals(0, $totals['qty_to_invoice']);
        $this->assertEquals(130, $totals['amount_ordered']);
        $this->assertEquals(130, $totals['amount_delivered']);
        $this->assertEquals(130, $totals['amount_invoiced']);
        $this->assertEquals(0, $totals['amount_to_invoice']);
    }

    public function test_ordered_quantity_excludes_cancelled_quantity(): void
    {
        [$order, $line] = $this->salesOrder(10, 100);
        $line->update(['quantity_cancelled' => 2.5]);

        $totals = $this->pivot([
            'row_dims' => ['product'],
            'measure' => self::MEASURES,
        ])['totals'];

        $this->assertEquals(7.5, $totals['qty_ordered']);
        $this->assertEquals(750, $totals['amount_ordered']);
        $this->assertEquals(0, $totals['qty_delivered']);
    }

    // ── Unbilled deliveries view ──────────────────────────────────────────

    public function test_unbilled_deliveries_lists_only_completed_lines_with_delivered_over_invoiced(): void
    {
        // Line A: delivered 6, invoiced 4 → unbilled 2 × 100 = 200.
        [$orderA, $lineA] = $this->salesOrder(10, 100);
        $this->deliver($lineA, 6);
        $this->postInvoice($lineA, 4, 100);

        // Line B: delivered 5, invoiced 5 → fully billed, excluded.
        [$orderB, $lineB] = $this->salesOrder(5, 100);
        $this->deliver($lineB, 5);
        $this->postInvoice($lineB, 5, 100);

        // Line C: delivered 5, nothing invoiced → unbilled 5 × 100 = 500.
        [$orderC, $lineC] = $this->salesOrder(8, 100);
        $this->deliver($lineC, 5);

        // A draft delivery has shipped nothing real yet → excluded.
        $draft = DeliveryOrder::create([
            'delivery_order_number' => 'DO-602-DRAFT-'.uniqid(),
            'sales_order_id' => $orderB->id,
            'customer_id' => $this->customer->id,
            'status' => 'draft',
            'delivery_date' => '2026-08-11',
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'created_by' => $this->admin->id,
        ]);
        DeliveryOrderLine::create([
            'delivery_order_id' => $draft->id,
            'sales_order_line_id' => $lineB->id,
            'product_id' => $this->product->id,
            'description' => '602 draft delivery line',
            'quantity_ordered' => 3,
            'quantity_delivered' => 3,
            'quantity_invoiced' => 0,
            'unit_price' => 100,
            'line_total' => 300,
        ]);

        $data = $this->unbilled();

        $rows = collect($data['deliveries']);
        $this->assertCount(2, $rows, 'Only lines with delivered > invoiced are unbilled');

        $this->assertEqualsCanonicalizing(
            [200.0, 500.0],
            $rows->pluck('amount_to_invoice')->map(fn ($v) => (float) $v)->all(),
        );

        $lineARow = $rows->firstWhere('quantity_to_invoice', 2);
        $this->assertNotNull($lineARow);
        $this->assertEquals(6, $lineARow['quantity_delivered']);
        $this->assertEquals(4, $lineARow['quantity_invoiced']);
        $this->assertSame('602 Customer', $lineARow['customer_name']);
        $this->assertSame('602 Widget', $lineARow['product_name']);

        $lineCRow = $rows->firstWhere('quantity_to_invoice', 5);
        $this->assertNotNull($lineCRow);
        $this->assertEquals(0, $lineCRow['quantity_invoiced']);
        $this->assertEquals(500, $lineCRow['amount_to_invoice']);

        $summary = $data['summary'];
        $this->assertEquals(2, $summary['line_count']);
        $this->assertEquals(7, $summary['total_quantity_to_invoice']);
        $this->assertEquals(700, $summary['total_amount_to_invoice']);
        $this->assertSame('USD', $summary['base_currency']);
    }

    public function test_unbilled_deliveries_honours_customer_and_legal_entity_filters(): void
    {
        [$orderA, $lineA] = $this->salesOrder(10, 100);
        $this->deliver($lineA, 6);

        $otherCustomer = Customer::factory()->create(['name' => '602 Other Customer']);
        [$orderB, $lineB] = $this->salesOrder(4, 100, $otherCustomer);
        $this->deliver($lineB, 4);

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => '602 Entity Group',
            'code' => 'E602',
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => '602 Entity A', 'code' => 'E602A',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => '602 Entity B', 'code' => 'E602B',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_orders')->where('id', $orderA->id)->update(['legal_entity_id' => $entityA]);
        DB::table('sales_orders')->where('id', $orderB->id)->update(['legal_entity_id' => $entityB]);
        DB::table('delivery_orders')->update(['legal_entity_id' => $entityA]);
        DB::table('delivery_orders')->where('sales_order_id', $orderB->id)->update(['legal_entity_id' => $entityB]);

        $byCustomer = $this->unbilled(['customer_id' => $this->customer->id]);
        $this->assertCount(1, $byCustomer['deliveries']);
        $this->assertEquals(6, $byCustomer['deliveries'][0]['quantity_to_invoice']);

        $byEntity = $this->unbilled(['legal_entity_id' => $entityB]);
        $this->assertCount(1, $byEntity['deliveries']);
        $this->assertEquals(4, $byEntity['deliveries'][0]['quantity_to_invoice']);

        // The period filter scopes by delivery date.
        $outOfPeriod = $this->unbilled([
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ]);
        $this->assertCount(0, $outOfPeriod['deliveries']);
    }

    public function test_unbilled_deliveries_endpoint_rejects_an_unknown_filter(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/unbilled-deliveries?group_by=product')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['filters']);
    }
}
