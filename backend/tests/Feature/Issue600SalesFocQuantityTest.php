<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Models\Product;
use App\Models\User;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TenantTestCase;

/**
 * Issue #600 (RPT-UX5): free-of-charge (bonus scheme) units counted in
 * quantity sold at zero revenue, which distorted average price and margin,
 * and nothing showed the value of the free goods given away.
 *
 * Acceptance criteria:
 *   - the product and customer tabs show separate paid qty, FOC qty and FOC
 *     list value columns;
 *   - average price and margin use the paid quantity only.
 *
 * FOC units are the ones flagged `is_foc` on a live (non-cancelled) medical
 * case line, mapped to the invoice line that bills them (or, before
 * invoicing, to the sales order line they were allocated from). Revenue is
 * unchanged.
 *
 * Driven through the real HTTP routes (router → middleware → controller →
 * SalesAnalyticsService).
 */
class Issue600SalesFocQuantityTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Sales FOC 600 Tenant');
        CompanySettings::query()->create(['base_currency' => 'USD']);
        $this->customer = Customer::factory()->create(['name' => '600 Hospital']);
        $this->product = Product::factory()->create([
            'name' => '600 Implant',
            'sku' => '600-'.uniqid(),
            'price' => 100,
            'cost' => 40,
            'track_inventory' => false,
        ]);
    }

    private function salesOrder(float $quantity, float $lineTotal, string $status = 'confirmed'): SalesOrderLine
    {
        $order = SalesOrder::create([
            'sales_order_number' => 'SO-600-'.uniqid(),
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'status' => $status,
            'accounting_status' => 'not_posted',
            'subtotal' => $lineTotal,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $lineTotal,
            'order_date' => '2026-09-10',
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'created_by' => $this->admin->id,
        ]);

        return SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'line_type' => 'product',
            'product_id' => $this->product->id,
            'description' => '600 SO line',
            'quantity' => $quantity,
            'unit_price' => 100,
            'line_total' => $lineTotal,
        ]);
    }

    private function postedInvoice(SalesOrderLine $soLine, float $quantity, float $lineTotal): InvoiceLine
    {
        $invoice = Invoice::create([
            'invoice_number' => 'INV-600-'.uniqid(),
            'customer_id' => $this->customer->id,
            'sales_order_id' => $soLine->sales_order_id,
            'invoice_date' => '2026-09-12',
            'due_date' => '2026-09-12',
            'status' => 'sent',
            'lifecycle_status' => 'posted',
            'subtotal' => $lineTotal,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $lineTotal,
            'balance' => $lineTotal,
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'created_by' => $this->admin->id,
        ]);

        return InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'sales_order_line_id' => $soLine->id,
            'line_type' => 'product',
            'product_id' => $this->product->id,
            'description' => '600 invoice line',
            'quantity' => $quantity,
            'unit_price' => 100,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_amount' => $quantity * 100 - $lineTotal,
            'line_total' => $lineTotal,
        ]);
    }

    /**
     * One medical case line. `$invoiceLine` set → the line was invoiced.
     */
    private function caseLine(
        SalesOrderLine $soLine,
        float $quantity,
        bool $foc,
        ?InvoiceLine $invoiceLine = null,
        string $status = 'invoiced',
    ): void {
        $case = MedicalCase::create([
            'customer_id' => $this->customer->id,
            'case_date' => '2026-09-11',
            'status' => $status,
            'created_by' => $this->admin->id,
        ]);

        MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $soLine->id,
            'invoice_item_id' => $invoiceLine?->id,
            'product_id' => $this->product->id,
            'allocated_qty' => $quantity,
            'delivered_qty' => $quantity,
            'invoiced_qty' => $invoiceLine ? $quantity : 0,
            'unit_price' => $foc ? 0 : 100,
            'is_foc' => $foc,
        ]);
    }

    private function report(string $endpoint): array
    {
        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/analytics/sales/{$endpoint}?start_date=2026-09-01&end_date=2026-09-30");
        $response->assertOk();

        return $response->json('data');
    }

    public function test_invoiced_foc_units_are_split_from_paid_quantity_on_product_and_customer_tabs(): void
    {
        // 5 units delivered for a case: 4 paid at 100, 1 free (bonus scheme)
        // billed on the same invoice line at net 0 — revenue 400.
        $soLine = $this->salesOrder(5, 400);
        $invoiceLine = $this->postedInvoice($soLine, 5, 400);
        $this->caseLine($soLine, 4, false, $invoiceLine);
        $this->caseLine($soLine, 1, true, $invoiceLine);
        // A cancelled case's FOC line is not a free unit given away.
        $this->caseLine($soLine, 1, true, $invoiceLine, 'cancelled');

        $product = collect($this->report('by-product')['products'])->firstWhere('id', $this->product->id);

        $this->assertNotNull($product);
        $this->assertSame(400.0, (float) $product['total_revenue'], 'Revenue is unchanged by FOC units.');
        $this->assertSame(4.0, (float) ($product['paid_quantity'] ?? null), 'Paid qty excludes FOC units.');
        $this->assertSame(1.0, (float) ($product['foc_quantity'] ?? null), 'FOC qty is reported separately.');
        $this->assertSame(100.0, (float) ($product['foc_list_value'] ?? null), 'FOC list value = FOC qty × list price.');
        $this->assertSame(100.0, (float) ($product['average_price'] ?? null), 'Average price = revenue / paid qty.');
        // Margin on paid units: cost 4 × 40 = 160, profit 240, margin 60%.
        $this->assertSame(160.0, (float) $product['total_cost']);
        $this->assertSame(240.0, (float) $product['total_profit']);
        $this->assertSame(60.0, (float) $product['profit_margin']);

        $customer = collect($this->report('by-customer')['customers'])->firstWhere('id', $this->customer->id);

        $this->assertNotNull($customer);
        $this->assertSame(400.0, (float) $customer['total_revenue']);
        $this->assertSame(4.0, (float) ($customer['paid_quantity'] ?? null));
        $this->assertSame(1.0, (float) ($customer['foc_quantity'] ?? null));
        $this->assertSame(100.0, (float) ($customer['foc_list_value'] ?? null));
    }

    public function test_allocated_foc_units_on_an_uninvoiced_order_are_split_too(): void
    {
        // Booking (no invoice yet): 3 units, 1 of them allocated free.
        $soLine = $this->salesOrder(3, 200);
        $this->caseLine($soLine, 2, false, null, 'allocated');
        $this->caseLine($soLine, 1, true, null, 'allocated');

        $product = collect($this->report('by-product')['products'])->firstWhere('id', $this->product->id);

        $this->assertNotNull($product);
        $this->assertSame(200.0, (float) $product['total_revenue']);
        $this->assertSame(2.0, (float) ($product['paid_quantity'] ?? null));
        $this->assertSame(1.0, (float) ($product['foc_quantity'] ?? null));
        $this->assertSame(100.0, (float) ($product['foc_list_value'] ?? null));
        $this->assertSame(100.0, (float) ($product['average_price'] ?? null));
    }
}
