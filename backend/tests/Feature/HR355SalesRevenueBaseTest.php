<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Services\AccountMappingService;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Analytics\SalesAnalyticsService;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosOrderItem;
use Modules\Sales\Services\SalesOrderService;
use Tests\TenantTestCase;

/**
 * Issue #355 (P0, [SALES-DASH]) — "Revenue" on the sales dashboard had no
 * documented definition and reconciled with nothing:
 *   - it was tax-INCLUSIVE (line totals carry tax) while the ledger books
 *     revenue net of tax;
 *   - document (header) discounts were never subtracted;
 *   - customer credit notes were not netted;
 *   - POS returns were excluded rather than netted;
 *   - every widget but by-customer summed document-currency amounts, so panels
 *     on the same page disagreed;
 *   - and the chart was labelled "Amount invoiced" while the base still counted
 *     un-invoiced orders by order date.
 *
 * The decided definition (issue acceptance criteria) is:
 *
 *   REVENUE = posted invoices (tax excluded, document discount subtracted,
 *             converted to base currency at the document snapshot rate)
 *           − posted customer credit notes (same base)
 *           + POS sales net of POS returns (returns are already negative)
 *           + un-invoiced bookings (sales orders with no posted invoice, same
 *             tax/discount/currency treatment — shown separately as
 *             `bookings_revenue`)
 *   dated by DOCUMENT date (invoice date / credit-note date / POS paid date /
 *   order date) and reported in the tenant base currency.
 *
 * Everything below goes through the real HTTP report endpoints (router →
 * middleware → controller → service) and compares against the GL movement of
 * the mapped `sales_revenue` account read through the journal models — the
 * PostingEngine is never mocked and no JournalEntry row is written by hand.
 */
class HR355SalesRevenueBaseTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Revenue Base 355 Tenant');

        CompanySettings::query()->create(['base_currency' => 'MYR']);

        $this->category = ProductCategory::create([
            'name' => 'HR355 Widgets',
            'is_active' => true,
        ]);

        $this->customer = Customer::factory()->create([
            'name' => 'HR355 Customer',
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'HR355 Widget',
            'sku' => 'HR355-'.uniqid(),
            'category_id' => $this->category->id,
            'price' => 1000,
            'cost' => 100,
            'track_inventory' => false,
        ]);
    }

    private function periodQuery(): string
    {
        return '?start_date='.now()->toDateString().'&end_date='.now()->toDateString();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────

    /**
     * A posted AR invoice with no sales order (direct accounting-origin
     * invoice), optionally with tax and a document (header) discount.
     *
     * @param  array<int, array{product_id:int, quantity:float, unit_price:float}>  $lines
     */
    private function postInvoice(array $lines, float $headerDiscount = 0.0, float $taxRate = 0.0): Invoice
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'auto_post' => true,
        ];

        if ($headerDiscount > 0) {
            $payload['discount_amount'] = $headerDiscount;
        }

        $payload['lines'] = array_map(fn (array $line) => [
            'line_type' => 'product',
            'product_id' => $line['product_id'] ?? $this->product->id,
            'description' => $line['description'] ?? 'HR355 invoice line',
            'quantity' => $line['quantity'] ?? 1,
            'unit_price' => $line['unit_price'],
            'tax_rate' => $line['tax_rate'] ?? $taxRate,
            'discount_amount' => $line['discount_amount'] ?? 0,
        ], $lines);

        $create = $this->actingAs($this->admin)
            ->postJson('/api/v1/accounting/origin/invoices', $payload);
        $create->assertCreated();

        $invoice = Invoice::findOrFail((int) $create->json('id'));
        $this->assertSame('posted', $invoice->lifecycle_status, 'Fixture invoice must be posted');

        return $invoice;
    }

    /**
     * A customer credit note against the invoice, posted through the real
     * credit-note endpoint (the create-from-invoice correction route is
     * separately broken and out of scope for #355).
     */
    private function postCreditNote(Invoice $invoice, float $netAmount, float $taxRate = 0.0, bool $post = true): CustomerCreditNote
    {
        $tax = round($netAmount * $taxRate / 100, 2);

        $creditNote = CustomerCreditNote::create([
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => $netAmount,
            'tax_amount' => $tax,
            'total_amount' => round($netAmount + $tax, 2),
            'reason' => 'HR355 credit',
        ]);

        $creditNote->lines()->create([
            'product_id' => $this->product->id,
            'description' => 'HR355 credit line',
            'quantity' => 1,
            'unit_price' => $netAmount,
            'tax_rate' => $taxRate,
        ]);

        if ($post) {
            $this->actingAs($this->admin)
                ->postJson("/api/v1/accounting/customers/credit-notes/{$creditNote->id}/post")
                ->assertOk();

            $this->assertSame('posted', $creditNote->fresh()->status, 'Credit note must be posted');
        }

        return $creditNote->fresh();
    }

    /**
     * A paid POS sale, or a POS return (negative amounts, is_return = true —
     * exactly the shape PosOrder::createReturn() writes).
     */
    private function posSale(float $netAmount, float $taxRate = 0.0, bool $isReturn = false): PosOrder
    {
        $sign = $isReturn ? -1 : 1;
        $tax = round(abs($netAmount) * $taxRate / 100, 2) * $sign;

        $order = PosOrder::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'paid',
            'is_return' => $isReturn,
            'subtotal' => $netAmount * $sign,
            'tax_amount' => $tax,
            'total' => ($netAmount * $sign) + $tax,
            'discount_amount' => 0,
            'currency' => 'MYR',
            'paid_at' => now(),
            'payment_method' => 'cash',
        ]);

        PosOrderItem::create([
            'pos_order_id' => $order->id,
            'product_id' => $this->product->id,
            'name' => $this->product->name,
            'quantity' => $sign,
            'unit_price' => abs($netAmount),
            'tax_rate' => $taxRate,
            'tax_amount' => $tax,
            // POS line_total is tax-INCLUSIVE (gross); a return writes it negative.
            'line_total' => (abs($netAmount) + abs($tax)) * $sign,
        ]);

        return $order;
    }

    /**
     * An un-invoiced sales order (a booking): confirmed, never invoiced.
     */
    private function unInvoicedBooking(float $netAmount, float $taxRate = 0.0, float $headerDiscount = 0.0, string $currency = 'MYR', float $rate = 1.0): \Modules\Sales\Models\SalesOrder
    {
        $order = app(SalesOrderService::class)->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'created_by' => $this->admin->id,
            'currency_code' => $currency,
            'exchange_rate' => $rate,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'HR355 booking line',
                'quantity' => 1,
                'unit_price' => $netAmount,
                'price_mode' => 'carried',
                'tax_rate' => $taxRate,
            ]],
        ]);

        DB::table('sales_orders')->where('id', $order->id)->update([
            'status' => 'confirmed',
            'order_date' => now()->toDateString(),
            'discount_amount' => $headerDiscount,
        ]);

        return $order->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Revenue read through the real HTTP report endpoints
    // ─────────────────────────────────────────────────────────────────────

    /** The dashboard's own aggregation: every product row's total_revenue. */
    private function dashboardRevenue(): float
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/by-product'.$this->periodQuery());
        $response->assertOk();

        return round(
            (float) collect($response->json('data.products'))->sum(fn ($row) => (float) $row['total_revenue']),
            2
        );
    }

    private function customerRevenue(): float
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/by-customer'.$this->periodQuery());
        $response->assertOk();

        return round(
            (float) collect($response->json('data.customers'))->sum(fn ($row) => (float) $row['total_revenue']),
            2
        );
    }

    private function categoryRevenue(): float
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/by-category'.$this->periodQuery());
        $response->assertOk();

        return round(
            (float) collect($response->json('data.categories'))->sum(fn ($row) => (float) $row['revenue']),
            2
        );
    }

    private function trendRevenue(): float
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/trends'.$this->periodQuery().'&interval=day');
        $response->assertOk();

        return round(
            (float) collect($response->json('data.trends'))->sum(fn ($row) => (float) $row['revenue']),
            2
        );
    }

    /**
     * Net movement of the mapped `sales_revenue` GL account for the period,
     * read from the posted journal entries (credits − debits).
     */
    private function glRevenueMovement(): float
    {
        $revenueAccountId = app(AccountMappingService::class)->resolveId('sales_revenue');

        $movement = JournalEntryLine::query()
            ->where('account_id', $revenueAccountId)
            ->whereHas('journalEntry', fn ($entry) => $entry
                ->whereDate('entry_date', now()->toDateString())
                ->where('status', 'posted'))
            ->get()
            ->sum(fn (JournalEntryLine $line) => (float) $line->credit - (float) $line->debit);

        return round((float) $movement, 2);
    }

    private function filter(): ReportFilter
    {
        return new ReportFilter(
            startDate: now()->startOfDay(),
            endDate: now()->endOfDay(),
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // (a) The dashboard reconciles with the ledger
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Acceptance: "dashboard revenue for a period equals the revenue account
     * movement in the GL (or the documented difference)". This is exact — no
     * documented difference — because every leg of the revenue account is a
     * posted invoice or a posted customer credit note:
     *
     *   INV-1  1,000.00 net + 6% tax          → revenue 1,000.00
     *   INV-2    500.00 net, 50.00 discount   → revenue   450.00
     *   CN-1    −200.00 net                    → revenue  −200.00
     *                                              GL       1,250.00
     */
    public function test_dashboard_revenue_equals_the_gl_revenue_account_movement(): void
    {
        $this->postInvoice([['unit_price' => 1000, 'tax_rate' => 6]]);
        $discounted = $this->postInvoice([['unit_price' => 500]], headerDiscount: 50);
        $this->postCreditNote($discounted, 200);

        $this->assertSame(1250.00, $this->glRevenueMovement(), 'GL revenue account movement for the period');
        $this->assertSame(
            $this->glRevenueMovement(),
            $this->dashboardRevenue(),
            'Sales-dashboard revenue must equal the GL revenue account movement'
        );
    }

    /**
     * The same reconciliation with tax on the credit note as well: the credit
     * arm must be net of tax too, not just the invoice arm.
     */
    public function test_gl_reconciliation_holds_when_the_credit_note_carries_tax(): void
    {
        $invoice = $this->postInvoice([['unit_price' => 800]]);
        $this->postCreditNote($invoice, 200, taxRate: 6);

        $this->assertSame(600.00, $this->glRevenueMovement(), 'GL revenue account movement for the period');
        $this->assertSame(
            $this->glRevenueMovement(),
            $this->dashboardRevenue(),
            'A credit note of 200.00 + 12.00 tax must reduce revenue by 200.00, not by its gross'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // (b) Tax excluded, discounts applied, credit notes netted, returns netted
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Tax is excluded from revenue: the ledger credits sales_revenue with the
     * pre-tax amount and books the tax on the tax-payable account.
     */
    public function test_revenue_is_tax_excluded(): void
    {
        $invoice = $this->postInvoice([['unit_price' => 1000, 'tax_rate' => 6]]);

        $this->assertSame(1060.00, (float) $invoice->fresh()->total, 'Fixture: 1,000.00 net + 60.00 tax');
        $this->assertSame(60.00, (float) $invoice->fresh()->tax_amount, 'Fixture tax amount');

        $this->assertSame(
            1000.00,
            $this->dashboardRevenue(),
            'Revenue is tax-excluded: the 60.00 output tax must not be reported as revenue'
        );
    }

    /**
     * A document (header) discount is subtracted from revenue, spread pro-rata
     * over the lines — the same base the invoice posting credits
     * (subtotal − discount_amount).
     */
    public function test_document_discount_is_subtracted_from_revenue(): void
    {
        $this->postInvoice([
            ['unit_price' => 600],
            ['unit_price' => 400, 'description' => 'second line'],
        ], headerDiscount: 100);

        $this->assertSame(
            900.00,
            $this->dashboardRevenue(),
            'Revenue must be net of the 100.00 document discount (1,000.00 − 100.00)'
        );
        $this->assertSame(
            900.00,
            $this->glRevenueMovement(),
            'The ledger books the same net-of-discount amount'
        );
    }

    /**
     * A posted credit note is netted; a draft one is not counted at all.
     */
    public function test_posted_credit_notes_are_netted_and_draft_ones_are_ignored(): void
    {
        $invoice = $this->postInvoice([['unit_price' => 1000]]);

        $this->postCreditNote($invoice, 250, post: false);

        $this->assertSame(
            1000.00,
            $this->dashboardRevenue(),
            'A draft credit note is not revenue and must not reduce the reported figure'
        );

        $this->postCreditNote($invoice, 250);

        $this->assertSame(
            750.00,
            $this->dashboardRevenue(),
            'A posted credit note must reduce revenue by its net amount'
        );
    }

    /**
     * POS returns are NETTED, not excluded: a 500.00 sale with a 200.00 return
     * reports 300.00. Before the fix the return arm was filtered out
     * (`is_return = false`) so the dashboard showed the full 500.00.
     */
    public function test_pos_returns_are_netted_against_pos_sales(): void
    {
        $this->posSale(500, taxRate: 6);
        $this->posSale(200, taxRate: 6, isReturn: true);

        $this->assertSame(
            300.00,
            $this->dashboardRevenue(),
            'POS revenue must be net of returns: 500.00 − 200.00 = 300.00 (both tax-excluded)'
        );
    }

    /**
     * A POS sale whose tax is excluded, next to an invoice with tax: both arms
     * report the same tax-excluded base.
     */
    public function test_pos_sales_are_tax_excluded_too(): void
    {
        $this->posSale(400, taxRate: 10);
        $this->postInvoice([['unit_price' => 400, 'tax_rate' => 10]]);

        $this->assertSame(
            800.00,
            $this->dashboardRevenue(),
            'Both arms are tax-excluded: 400.00 POS + 400.00 invoiced'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // (c) Every widget uses the same base
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Acceptance: "every widget uses the same base". A mixed period — invoice
     * with tax and discount, credit note, POS sale with a return and an
     * un-invoiced booking — must total identically in all four reports.
     */
    public function test_every_widget_reports_the_same_revenue_base(): void
    {
        $invoice = $this->postInvoice([['unit_price' => 1000, 'tax_rate' => 6]], headerDiscount: 100);
        $this->postCreditNote($invoice, 150);
        $this->posSale(300, taxRate: 6);
        $this->posSale(100, isReturn: true);
        $this->unInvoicedBooking(250, taxRate: 6);

        $expected = 900.00 - 150.00 + 300.00 - 100.00 + 250.00;

        $this->assertSame($expected, $this->dashboardRevenue(), 'by-product (the dashboard total)');
        $this->assertSame($expected, $this->customerRevenue(), 'by-customer must use the same base');
        $this->assertSame($expected, $this->trendRevenue(), 'trends must use the same base');
        $this->assertSame($expected, $this->categoryRevenue(), 'by-category must use the same base');
    }

    /**
     * Acceptance: base currency. A booking denominated in a foreign currency
     * is reported converted at the document snapshot rate, and the report says
     * which basis it is measuring.
     */
    public function test_revenue_is_reported_in_base_currency(): void
    {
        $this->unInvoicedBooking(100, currency: 'USD', rate: 4.5);

        $this->assertSame(
            450.00,
            $this->dashboardRevenue(),
            'USD 100.00 at the 4.50 snapshot rate is MYR 450.00 in the tenant base currency'
        );

        $byProduct = app(SalesAnalyticsService::class)->getSalesByProduct($this->filter());

        $this->assertSame('MYR', $byProduct['summary']['base_currency'] ?? null, 'The report states its base currency');
        $this->assertStringContainsString(
            'tax',
            strtolower((string) ($byProduct['summary']['revenue_basis'] ?? '')),
            'The report states what it measures'
        );
    }

    /**
     * Bookings (un-invoiced sales orders) are surfaced separately from
     * invoiced revenue instead of silently blending into the revenue line.
     */
    public function test_bookings_are_reported_separately_from_invoiced_revenue(): void
    {
        $invoice = $this->postInvoice([['unit_price' => 700]]);
        $this->unInvoicedBooking(300);

        $summary = app(SalesAnalyticsService::class)->getSalesByProduct($this->filter())['summary'];

        $this->assertSame(700.00, (float) $summary['invoiced_revenue'], 'Invoiced revenue only');
        $this->assertSame(300.00, (float) $summary['bookings_revenue'], 'Un-invoiced bookings only');
        $this->assertSame(1000.00, (float) $summary['total_revenue'], 'Both together are the headline figure');

        $this->assertNotNull($invoice->id);
    }

    /**
     * The credit-note and POS arms report what they are, in the documented
     * currency split: document-currency amounts for the currency view and
     * base-currency amounts for everything else.
     */
    public function test_sales_by_currency_reports_document_and_base_amounts(): void
    {
        $this->unInvoicedBooking(100, currency: 'USD', rate: 4.5);
        $this->unInvoicedBooking(200, currency: 'MYR', rate: 1.0);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/by-currency'.$this->periodQuery());
        $response->assertOk();

        $buckets = collect($response->json('data.currencies'))->keyBy('currency_code');

        $this->assertSame(100.00, round((float) $buckets['USD']['total_revenue'], 2), 'USD document amount');
        $this->assertSame(450.00, round((float) $buckets['USD']['base_revenue'], 2), 'USD base equivalent');
        $this->assertSame(200.00, round((float) $buckets['MYR']['total_revenue'], 2), 'MYR document amount');
        $this->assertSame(200.00, round((float) $buckets['MYR']['base_revenue'], 2), 'MYR is the base currency: no conversion');
        $this->assertSame(
            650.00,
            round((float) $response->json('data.summary.total_base_revenue'), 2),
            'The currency view totals the BASE amounts the other widgets use'
        );
        $this->assertSame(
            $this->dashboardRevenue(),
            round((float) $response->json('data.summary.total_base_revenue'), 2),
            'by-currency base total must equal the by-product total'
        );
    }
}
