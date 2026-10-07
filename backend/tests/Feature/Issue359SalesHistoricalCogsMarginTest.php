<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Services\AccountMappingService;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Tests\TenantTestCase;

/**
 * Issue #359 ([SALES-DASH] P2, S11) — profit and margin in the sales
 * analytics (by-product, by-category, profit-margins, pivot) were costed at
 * `quantity × products.cost`, i.e. TODAY's cost. A weighted-average update
 * after a sale silently rewrote every past period's margin, and the report's
 * cost never matched the COGS actually posted. `average_profit_margin` was
 * also a simple average of row margins instead of being weighted by revenue.
 *
 * Acceptance (issue):
 *   - cost comes from the posted COGS (cost at time of sale);
 *   - margin is weighted by revenue;
 *   - changing the product cost after a sale does not change the past margin.
 *
 * Every assertion goes through the real HTTP routes (router → middleware →
 * controller → service). Invoices are posted through the real invoice
 * endpoint, so COGS is written by the PostingEngine; the ledger side is read
 * from the posted journal lines on the mapped `cogs` account.
 */
class Issue359SalesHistoricalCogsMarginTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private ProductCategory $category;

    private Product $productA;

    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Historical COGS 359 Tenant');

        CompanySettings::query()->create(['base_currency' => 'MYR']);

        $this->category = ProductCategory::create(['name' => '359 Category', 'is_active' => true]);

        $this->customer = Customer::factory()->create(['name' => '359 Customer', 'is_active' => true]);

        $this->productA = Product::factory()->create([
            'name' => '359 Widget A',
            'sku' => '359A-'.uniqid(),
            'category_id' => $this->category->id,
            'price' => 300,
            'cost' => 100,
            'track_inventory' => false,
        ]);

        $this->productB = Product::factory()->create([
            'name' => '359 Widget B',
            'sku' => '359B-'.uniqid(),
            'category_id' => $this->category->id,
            'price' => 100,
            'cost' => 40,
            'track_inventory' => false,
        ]);
    }

    private function periodQuery(): string
    {
        return '?start_date='.now()->toDateString().'&end_date='.now()->toDateString();
    }

    private function postInvoice(Product $product, float $quantity, float $unitPrice): Invoice
    {
        $create = $this->actingAs($this->admin)->postJson('/api/v1/accounting/origin/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'auto_post' => true,
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $product->id,
                'description' => '359 line',
                'quantity' => $quantity,
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

    /** Net debit of the mapped COGS account for today's posted entries. */
    private function ledgerCogs(): float
    {
        $cogsAccountId = app(AccountMappingService::class)->resolveId('cogs');

        return round((float) JournalEntryLine::query()
            ->where('account_id', $cogsAccountId)
            ->whereHas('journalEntry', fn ($entry) => $entry
                ->whereDate('entry_date', now()->toDateString())
                ->where('status', 'posted'))
            ->get()
            ->sum(fn (JournalEntryLine $line) => (float) $line->debit - (float) $line->credit), 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function productRow(array $rows, Product $product): array
    {
        $row = collect($rows)->firstWhere('id', $product->id);
        $this->assertNotNull($row, "Row for {$product->name} missing");

        return $row;
    }

    /** Post two sales at cost 100 / 40, then re-cost both products. */
    private function sellThenRecost(): void
    {
        $this->postInvoice($this->productA, 2, 300);   // revenue 600, COGS 2 × 100 = 200
        $this->postInvoice($this->productB, 1, 100);   // revenue 100, COGS 1 × 40 = 40

        $this->assertSame(240.0, $this->ledgerCogs(), 'Fixture: the ledger carries the COGS posted at sale');

        // Weighted-average cost moves AFTER the sales were posted.
        $this->productA->update(['cost' => 250]);
        $this->productB->update(['cost' => 90]);
    }

    public function test_past_product_margin_stays_at_posted_cogs_after_cost_change(): void
    {
        $this->sellThenRecost();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/by-product'.$this->periodQuery());
        $response->assertOk();

        $a = $this->productRow($response->json('data.products'), $this->productA);
        $this->assertEquals(600.0, (float) $a['total_revenue']);
        $this->assertEquals(200.0, (float) $a['total_cost'], 'Cost is the COGS posted at sale, not today\'s cost × qty');
        $this->assertEquals(400.0, (float) $a['total_profit']);
        $this->assertEquals(66.67, (float) $a['profit_margin']);

        $b = $this->productRow($response->json('data.products'), $this->productB);
        $this->assertEquals(40.0, (float) $b['total_cost']);
        $this->assertEquals(60.0, (float) $b['total_profit']);
        $this->assertEquals(60.0, (float) $b['profit_margin']);

        // The report's summary (the profit-margins endpoint carries it): its
        // COGS equals the ledger COGS for the period, and the margin is
        // weighted by revenue: (700 − 240) / 700 = 65.71 %, not the simple
        // average of 66.67 % and 60 % (63.33 %).
        $margins = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/profit-margins'.$this->periodQuery());
        $margins->assertOk();
        $this->assertEquals($this->ledgerCogs(), (float) $margins->json('data.summary.total_cost'));
        $this->assertEquals(460.0, (float) $margins->json('data.summary.total_profit'));
        $this->assertEquals(65.71, (float) $margins->json('data.summary.average_profit_margin'));
    }

    public function test_category_and_profit_margin_reports_use_posted_cogs(): void
    {
        $this->sellThenRecost();

        $category = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/by-category'.$this->periodQuery());
        $category->assertOk();

        $row = collect($category->json('data.categories'))->firstWhere('category_id', $this->category->id);
        $this->assertNotNull($row);
        $this->assertEquals(240.0, (float) $row['cost']);
        $this->assertEquals(460.0, (float) $row['profit']);
        $this->assertEquals(65.71, (float) $row['profit_margin']);

        $margins = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/profit-margins'.$this->periodQuery());
        $margins->assertOk();

        $a = $this->productRow($margins->json('data.data'), $this->productA);
        $this->assertEquals(200.0, (float) $a['total_cost']);
        $this->assertEquals(66.67, (float) $a['profit_margin']);
    }

    public function test_pivot_cost_and_margin_use_posted_cogs(): void
    {
        $this->sellThenRecost();

        $pivot = $this->actingAs($this->admin)->getJson('/api/v1/analytics/sales/pivot'.$this->periodQuery()
            .'&row_dims[]=product&measure[]=net_revenue&measure[]=cost&measure[]=margin');
        $pivot->assertOk();

        $this->assertEquals(240.0, (float) $pivot->json('data.totals.cost'));
        $this->assertEquals(460.0, (float) $pivot->json('data.totals.margin'));
    }
}
