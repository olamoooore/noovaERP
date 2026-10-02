<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Services\SalesOrderService;
use Tests\TenantTestCase;

/**
 * Issue #590 (P0, RP1) — the sales report union read only pos_orders and
 * sales_orders, so posted invoices without a Sales Order (direct/accounting
 * origin, quote→invoice, DO→invoice, imports) were invisible in the product,
 * customer, trend and category reports.
 *
 * Real HTTP through the router: invoice creation/post and every report
 * endpoint. The report revenue base is posted invoices (tax-excluded) plus POS
 * net of returns, minus posted customer credit notes; an invoiced sales order
 * counts once (through its invoice), and POS-synced sales orders stay excluded
 * (#236).
 */
class HR590SalesReportDirectInvoiceTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Sales Report 590 Tenant');

        CompanySettings::query()->create(['base_currency' => 'MYR']);

        $this->category = ProductCategory::create([
            'name' => 'HR590 Widgets',
            'is_active' => true,
        ]);

        $this->customer = Customer::factory()->create([
            'name' => 'HR590 Direct Customer',
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'HR590 Direct Widget',
            'sku' => 'HR590-'.uniqid(),
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

    /**
     * A posted invoice with no sales order at all, created through the real
     * accounting-origin API (direct AR invoice: quote→invoice / DO→invoice /
     * import all land here without a backing Sales Order).
     */
    private function createAndPostDirectInvoice(float $unitPrice, int $quantity = 1): Invoice
    {
        $create = $this->actingAs($this->admin)->postJson('/api/v1/accounting/origin/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'auto_post' => true,
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => 'Direct invoice line',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $create->assertCreated();

        $invoice = Invoice::findOrFail((int) $create->json('id'));
        $this->assertNull($invoice->sales_order_id, 'This direct invoice must not have a backing sales order');
        $this->assertSame('posted', $invoice->lifecycle_status, 'The invoice must be posted before it can count as revenue');

        return $invoice;
    }

    /**
     * A sales order through the real service, moved to confirmed so it is
     * invoiceable (mirrors the other sales-analytics tests).
     */
    private function confirmedSalesOrder(float $unitPrice, int $quantity = 1): \Modules\Sales\Models\SalesOrder
    {
        $order = app(SalesOrderService::class)->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'created_by' => $this->admin->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Invoiced sales order line',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'price_mode' => 'carried',
                'tax_rate' => 0,
            ]],
        ]);

        DB::table('sales_orders')->where('id', $order->id)->update([
            'status' => 'confirmed',
            'order_date' => now()->toDateString(),
        ]);

        return $order->fresh();
    }

    private function productRevenue(): float
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/by-product'.$this->periodQuery());
        $response->assertOk();

        $row = collect($response->json('data.products'))->firstWhere('id', $this->product->id);

        return $row ? round((float) $row['total_revenue'], 2) : 0.0;
    }

    private function customerRevenue(): float
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/by-customer'.$this->periodQuery());
        $response->assertOk();

        $row = collect($response->json('data.customers'))->firstWhere('id', $this->customer->id);

        return $row ? round((float) $row['total_revenue'], 2) : 0.0;
    }

    private function trendRevenue(): float
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/trends'.$this->periodQuery().'&interval=day');
        $response->assertOk();

        return round((float) collect($response->json('data.trends'))->sum('revenue'), 2);
    }

    private function categoryRevenue(): float
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/by-category'.$this->periodQuery());
        $response->assertOk();

        $row = collect($response->json('data.categories'))
            ->firstWhere('category_id', $this->category->id);

        return $row ? round((float) $row['revenue'], 2) : 0.0;
    }

    /**
     * Acceptance: a direct invoice (no SO) for RM 1,000, posted, appears in
     * the product, customer, trend and category reports for its date.
     */
    public function test_direct_invoice_without_sales_order_appears_in_every_sales_report(): void
    {
        $this->createAndPostDirectInvoice(1000);

        $this->assertSame(1000.0, $this->productRevenue(), 'Direct invoice product revenue missing from by-product');
        $this->assertSame(1000.0, $this->customerRevenue(), 'Direct invoice revenue missing from by-customer');
        $this->assertSame(1000.0, $this->trendRevenue(), 'Direct invoice revenue missing from trends');
        $this->assertSame(1000.0, $this->categoryRevenue(), 'Direct invoice revenue missing from by-category');
    }

    /**
     * The CreateInvoicePage path now creates a backing SalesOrder. Posting its
     * invoice must leave the period at 1,000 — never the SO + invoice (2,000).
     */
    public function test_create_invoice_page_invoice_is_not_double_counted_with_its_backing_order(): void
    {
        $create = $this->actingAs($this->admin)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Direct invoice line',
                'quantity' => 1,
                'unit_price' => 1000,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $create->assertCreated();

        $invoiceId = (int) $create->json('id');
        $this->assertNotNull(Invoice::findOrFail($invoiceId)->sales_order_id, 'The store path creates a backing sales order');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoiceId}/send")
            ->assertOk();

        $this->assertSame(1000.0, $this->productRevenue(), 'Backing order + invoice must count once');
        $this->assertSame(1000.0, $this->customerRevenue(), 'Backing order + invoice must count once');
    }

    /**
     * Acceptance: no double counting. An SO that is invoiced counts once
     * (through its invoice); a POS-synced SO is still excluded (#236).
     */
    public function test_invoiced_sales_order_counts_once_and_pos_synced_order_is_excluded(): void
    {
        // SO 500 -> posted invoice 500. Must contribute 500, not 1000.
        $order = $this->confirmedSalesOrder(500);
        $invoice = app(SalesOrderService::class)->createInvoice($order);
        $this->assertSame($order->id, $invoice->sales_order_id, 'The invoice must be linked to the sales order');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/invoices/{$invoice->id}/send")
            ->assertOk();
        $this->assertSame('posted', $invoice->fresh()->lifecycle_status);

        // A POS sale synced into sales_orders (channel='pos'): in production the
        // same revenue sits in pos_orders, so the SO row must stay excluded.
        $posSynced = $this->confirmedSalesOrder(700);
        DB::table('sales_orders')->where('id', $posSynced->id)->update(['channel' => 'pos']);
        DB::table('sales_order_lines')->where('sales_order_id', $posSynced->id)->update(['line_total' => 700]);

        $this->assertSame(500.0, $this->productRevenue(), 'Invoiced SO must count once; POS-synced SO must be excluded');
        $this->assertSame(500.0, $this->customerRevenue(), 'Invoiced SO must count once; POS-synced SO must be excluded');
        $this->assertSame(500.0, $this->trendRevenue(), 'Invoiced SO must count once; POS-synced SO must be excluded');
        $this->assertSame(500.0, $this->categoryRevenue(), 'Invoiced SO must count once; POS-synced SO must be excluded');
    }
}