<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Services\AccountMappingService;
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
 * Issue #604 (RPT-D1) — every sales report needs a "ties to GL" footer: the
 * report's net revenue next to the posted GL revenue for the SAME filters,
 * and, when they differ, the difference broken down by cause with links to
 * the documents that explain it (unposted invoices, manual journals, ...).
 *
 * Real HTTP through the router: invoices/SOs/manual journals are created and
 * posted through the real endpoints, and the reconciliation is read through
 * GET /api/v1/analytics/sales/reconciliation — the same filter contract as
 * the six sales report endpoints (#593: unknown filters are a 422).
 *
 * Revenue recognition here is deliberately NOT redefined (#395 stays open):
 * the report side is exactly what SalesAnalyticsService already reports for
 * the passed filters, and the GL side is the sum of posted journal lines on
 * revenue accounts for the same period.
 */
class Issue604SalesReportGlReconciliationTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('GL Footer 604 Tenant');

        CompanySettings::query()->create(['base_currency' => 'MYR']);

        $this->category = ProductCategory::create([
            'name' => 'Issue604 Widgets',
            'is_active' => true,
        ]);

        $this->customer = Customer::factory()->create([
            'name' => 'Issue604 Customer',
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Issue604 Widget',
            'sku' => 'ISSUE604-'.uniqid(),
            'category_id' => $this->category->id,
            'price' => 1000,
            'cost' => 100,
            'track_inventory' => false,
        ]);
    }

    private function periodQuery(string $extra = ''): string
    {
        return '?start_date='.now()->toDateString().'&end_date='.now()->toDateString().$extra;
    }

    private function reconcile(string $extra = ''): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/reconciliation'.$this->periodQuery($extra));
    }

    /** The report's own net revenue for the same filters, via the live report. */
    private function reportNetRevenue(string $extra = ''): float
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/by-product'.$this->periodQuery($extra));
        $response->assertOk();

        return round((float) collect($response->json('data.products'))->sum('total_revenue'), 2);
    }

    /** A posted AR invoice created through the real accounting-origin API. */
    private function postDirectInvoice(float $unitPrice, ?Customer $customer = null): Invoice
    {
        $create = $this->actingAs($this->admin)->postJson('/api/v1/accounting/origin/invoices', [
            'customer_id' => ($customer ?? $this->customer)->id,
            'invoice_date' => now()->toDateString(),
            'auto_post' => true,
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => 'Issue604 invoice line',
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $create->assertCreated();

        $invoice = Invoice::findOrFail((int) $create->json('id'));
        $this->assertSame('posted', $invoice->lifecycle_status, 'Fixture invoice must be posted');

        return $invoice;
    }

    /**
     * A confirmed sales order whose invoice exists but is NOT posted — the
     * report books the order (it is still an un-invoiced booking as far as
     * the #355 base is concerned) while the GL has nothing for it.
     */
    private function unpostedInvoice(float $unitPrice): array
    {
        $order = app(SalesOrderService::class)->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'created_by' => $this->admin->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Issue604 booking line',
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'price_mode' => 'carried',
                'tax_rate' => 0,
            ]],
        ]);

        DB::table('sales_orders')->where('id', $order->id)->update([
            'status' => 'confirmed',
            'order_date' => now()->toDateString(),
        ]);

        $invoice = app(SalesOrderService::class)->createInvoice($order->fresh());
        $this->assertNotSame(
            'posted',
            $invoice->fresh()->lifecycle_status,
            'Fixture invoice must stay unposted so the GL has nothing to show'
        );

        return [$order->fresh(), $invoice->fresh()];
    }

    /** A balanced manual journal crediting the mapped sales revenue account. */
    private function postManualJournal(float $amount): int
    {
        $revenueAccountId = app(AccountMappingService::class)->resolveId('sales_revenue');
        $expenseAccountId = app(AccountMappingService::class)->resolveId('general_expense');

        $create = $this->actingAs($this->admin)->postJson('/api/v1/accounting/journal-entries', [
            'entry_date' => now()->toDateString(),
            'description' => 'Issue604 manual revenue adjustment',
            'reference' => 'ISSUE604-MJ',
            'lines' => [
                [
                    'account_id' => $expenseAccountId,
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => 'Manual debit',
                ],
                [
                    'account_id' => $revenueAccountId,
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => 'Manual credit to revenue',
                ],
            ],
        ]);
        $create->assertCreated();
        $entryId = (int) $create->json('id');

        $post = $this->actingAs($this->admin)
            ->postJson("/api/v1/accounting/journal-entries/{$entryId}/post");
        $this->assertSame(200, $post->status(), 'Manual journal must post: '.json_encode($post->json()));

        return $entryId;
    }

    /** The cause codes in the payload, amounts keyed by code. */
    private function causeAmounts(array $data): array
    {
        $amounts = [];
        foreach ($data['causes'] ?? [] as $cause) {
            $amounts[$cause['code']] = round(($amounts[$cause['code']] ?? 0) + (float) $cause['amount'], 2);
        }

        return $amounts;
    }

    /**
     * Acceptance (equal case): with everything posted, the footer shows both
     * totals and they agree — no causes, and the report side is exactly what
     * the sales report itself reports for the same filters.
     */
    public function test_reconciled_period_reports_both_totals_and_no_difference(): void
    {
        $this->postDirectInvoice(1000);

        $response = $this->reconcile();
        $response->assertOk()->assertJsonPath('success', true);

        $data = $response->json('data');

        $this->assertSame(1000.0, (float) $data['report_net_revenue']);
        $this->assertSame(1000.0, (float) $data['gl_revenue']);
        $this->assertSame(0.0, (float) $data['difference']);
        $this->assertTrue($data['reconciled'], 'Posted revenue must tie to the GL');
        $this->assertSame([], $data['causes']);
        $this->assertSame('MYR', $data['base_currency']);

        // The reconciliation's report side must equal the report's own total
        // for the SAME filters — not a second, differently-defined number.
        $this->assertSame($this->reportNetRevenue(), (float) $data['report_net_revenue']);
    }

    /**
     * Acceptance (difference): a confirmed order whose invoice was never
     * posted is revenue the report shows and the GL does not. The difference
     * equals that invoice's amount and names the invoice that explains it.
     */
    public function test_unposted_invoice_explains_the_difference_and_links_the_document(): void
    {
        [, $invoice] = $this->unpostedInvoice(1000);

        $response = $this->reconcile();
        $response->assertOk();

        $data = $response->json('data');

        $this->assertSame(1000.0, (float) $data['report_net_revenue'], 'The booking is still in the report');
        $this->assertSame(0.0, (float) $data['gl_revenue'], 'Nothing was posted to the GL');
        $this->assertSame(1000.0, (float) $data['difference']);
        $this->assertFalse($data['reconciled']);

        $amounts = $this->causeAmounts($data);
        $this->assertArrayHasKey('unposted_invoice', $amounts, 'The unposted invoice must be a named cause');
        $this->assertSame(1000.0, $amounts['unposted_invoice']);

        $invoiceDoc = collect($data['causes'])
            ->firstWhere('code', 'unposted_invoice');
        $document = collect($invoiceDoc['documents'])->firstWhere('type', 'invoice');
        $this->assertNotNull($document, 'The cause must link the invoice document');
        $this->assertSame($invoice->id, (int) $document['id']);
        $this->assertNotEmpty($document['url'], 'The document must be linkable from the footer');

        // The causes must explain the whole difference, not part of it.
        $this->assertEqualsWithDelta(
            (float) $data['difference'],
            array_sum($amounts),
            0.01,
            'Causes must add up to the reported difference'
        );
    }

    /**
     * Acceptance (difference): a manual journal crediting a revenue account
     * is money the GL has and the report does not — listed by cause, linked
     * to the journal entry.
     */
    public function test_manual_journal_revenue_is_listed_as_a_cause_with_its_entry(): void
    {
        $this->postDirectInvoice(1000);
        $entryId = $this->postManualJournal(500);

        $response = $this->reconcile();
        $response->assertOk();

        $data = $response->json('data');

        $this->assertSame(1000.0, (float) $data['report_net_revenue']);
        $this->assertSame(1500.0, (float) $data['gl_revenue'], 'GL revenue includes the manual journal');
        $this->assertSame(-500.0, (float) $data['difference']);
        $this->assertFalse($data['reconciled']);

        $amounts = $this->causeAmounts($data);
        $this->assertArrayHasKey('manual_journal', $amounts);
        $this->assertSame(-500.0, $amounts['manual_journal']);

        $cause = collect($data['causes'])->firstWhere('code', 'manual_journal');
        $document = collect($cause['documents'])->firstWhere('type', 'journal_entry');
        $this->assertNotNull($document, 'The cause must link the journal entry');
        $this->assertSame($entryId, (int) $document['id']);

        $this->assertEqualsWithDelta((float) $data['difference'], array_sum($amounts), 0.01);
    }

    /**
     * Acceptance (same filters): a dimension filter narrows the GL side too,
     * so a customer-filtered report reconciles against that customer's posted
     * revenue instead of the whole tenant's.
     */
    public function test_dimension_filters_apply_to_both_sides_of_the_reconciliation(): void
    {
        $otherCustomer = Customer::factory()->create([
            'name' => 'Issue604 Other Customer',
            'is_active' => true,
        ]);

        $this->postDirectInvoice(1000, $this->customer);
        $this->postDirectInvoice(2000, $otherCustomer);

        $extra = '&customer_id='.$this->customer->id;

        $response = $this->reconcile($extra);
        $response->assertOk();

        $data = $response->json('data');

        $this->assertSame(1000.0, (float) $data['report_net_revenue']);
        $this->assertSame(1000.0, (float) $data['gl_revenue'], 'The GL side must honour the customer filter');
        $this->assertSame(0.0, (float) $data['difference']);
        $this->assertSame($this->reportNetRevenue($extra), (float) $data['report_net_revenue']);
    }

    /**
     * The reconciliation must speak the same filter contract as the six sales
     * report endpoints (#593): a filter it does not understand is a 422, not
     * a silently ignored parameter.
     */
    public function test_unknown_filters_are_rejected_like_the_other_sales_reports(): void
    {
        $this->reconcile('&nonsense=1')->assertStatus(422);
    }
}
