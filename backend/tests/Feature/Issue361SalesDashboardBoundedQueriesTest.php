<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * Issue #361 ([SALES-DASH] P2, S13) — every dashboard load ran eight heavy,
 * unbounded aggregate queries: the KPI tiles pulled the FULL by-product report
 * per period only to add up revenue on the client, the product and customer
 * widgets returned every row, and the trend chart always asked for daily
 * points, so the year view plotted up to 365 of them.
 *
 * Acceptance (issue):
 *   - a single `/analytics/sales/summary` endpoint for the KPI totals and
 *     their comparison;
 *   - Top-N limits on the product and customer widgets;
 *   - the trend interval adapts to the range (day, week or month).
 *
 * Every assertion goes through the real HTTP routes (router → middleware →
 * controller → service); invoices are posted through the real endpoint.
 */
class Issue361SalesDashboardBoundedQueriesTest extends TenantTestCase
{
    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Bounded Dashboard 361 Tenant');

        CompanySettings::query()->create(['base_currency' => 'MYR']);

        $this->product = Product::factory()->create([
            'name' => '361 Widget',
            'sku' => '361-'.uniqid(),
            'price' => 300,
            'cost' => 100,
            'track_inventory' => false,
        ]);
    }

    private function postInvoice(Customer $customer, float $quantity, float $unitPrice): Invoice
    {
        $create = $this->actingAs($this->admin)->postJson('/api/v1/accounting/origin/invoices', [
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'auto_post' => true,
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => '361 line',
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

    /**
     * Run one request and count the heavy sales aggregates it executed —
     * every one of them scans the #355 line-item union (POS + bookings +
     * invoices + credit notes), so each reads `pos_order_items`.
     *
     * @return array{0: \Illuminate\Testing\TestResponse, 1: int}
     */
    private function getCountingAggregates(string $uri): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($this->admin)->getJson($uri);
        $aggregates = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains($query['query'], 'pos_order_items'))
            ->count();
        DB::disableQueryLog();

        return [$response, $aggregates];
    }

    public function test_summary_endpoint_returns_kpi_totals_and_comparison_in_bounded_queries(): void
    {
        $this->postInvoice(Customer::factory()->create(['is_active' => true]), 2, 300);   // 600, 2 units
        $this->postInvoice(Customer::factory()->create(['is_active' => true]), 1, 150);   // 150, 1 unit

        $today = now()->toDateString();
        [$response, $aggregates] = $this->getCountingAggregates(
            "/api/v1/analytics/sales/summary?start_date={$today}&end_date={$today}"
            .'&comparison=previous_period&include_today=1'
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['data' => [
            'period' => ['start_date', 'end_date'],
            'summary' => [
                'total_revenue', 'invoiced_revenue', 'bookings_revenue',
                'total_quantity', 'order_count', 'average_order_value', 'base_currency',
            ],
            'comparison' => ['mode', 'period' => ['start_date', 'end_date'], 'summary' => [
                'total_revenue' => ['previous', 'delta', 'delta_percent'],
            ]],
            'today' => ['total_revenue'],
        ]]);

        $this->assertEquals(750.0, (float) $response->json('data.summary.total_revenue'));
        $this->assertEquals(3.0, (float) $response->json('data.summary.total_quantity'));
        $this->assertSame(2, $response->json('data.summary.order_count'));
        $this->assertEquals(375.0, (float) $response->json('data.summary.average_order_value'));
        $this->assertSame('MYR', $response->json('data.summary.base_currency'));

        // Comparison: yesterday had nothing.
        $this->assertSame('previous_period', $response->json('data.comparison.mode'));
        $this->assertEquals(0.0, (float) $response->json('data.comparison.summary.total_revenue.previous'));
        $this->assertEquals(750.0, (float) $response->json('data.comparison.summary.total_revenue.delta'));
        $this->assertEquals(750.0, (float) $response->json('data.today.total_revenue'));

        // No row lists: the KPI tiles need totals, not every product.
        $this->assertNull($response->json('data.products'));
        $this->assertNull($response->json('data.data'));

        // One aggregate per window: period, comparison and today.
        $this->assertLessThanOrEqual(3, $aggregates, "Summary ran {$aggregates} heavy aggregates");
    }

    public function test_customer_and_product_widgets_honour_top_n(): void
    {
        foreach ([1, 2, 3] as $quantity) {
            $this->postInvoice(Customer::factory()->create(['is_active' => true]), $quantity, 100);
        }

        $period = '?start_date='.now()->toDateString().'&end_date='.now()->toDateString();

        $customers = $this->actingAs($this->admin)->getJson('/api/v1/analytics/sales/by-customer'.$period.'&top=2');
        $customers->assertOk();
        $rows = $customers->json('data.customers');
        $this->assertCount(2, $rows);
        $this->assertEquals([300.0, 200.0], array_map(fn ($row) => (float) $row['total_revenue'], $rows));

        $products = $this->actingAs($this->admin)->getJson('/api/v1/analytics/sales/by-product'.$period.'&top=1');
        $products->assertOk();
        $this->assertCount(1, $products->json('data.products'));
    }

    public function test_year_view_trend_is_bucketed_monthly_when_interval_is_auto(): void
    {
        $year = (int) now()->format('Y');

        [$response, $aggregates] = $this->getCountingAggregates(
            "/api/v1/analytics/sales/trends?start_date={$year}-01-01&end_date={$year}-12-31&interval=auto"
        );

        $response->assertOk();
        $this->assertSame('month', $response->json('data.interval'));
        $trends = $response->json('data.trends');
        $this->assertCount(12, $trends, 'A year is 12 monthly points, not 365 daily ones');
        $this->assertSame("{$year}-01", $trends[0]['period']);
        $this->assertSame("{$year}-12", $trends[11]['period']);
        $this->assertSame(1, $aggregates);
    }

    public function test_auto_interval_adapts_to_the_range(): void
    {
        $start = Carbon::parse('2026-01-05'); // a Monday

        // Up to a month: daily.
        $week = $this->actingAs($this->admin)->getJson('/api/v1/analytics/sales/trends?start_date='
            .$start->toDateString().'&end_date='.$start->copy()->addDays(6)->toDateString().'&interval=auto');
        $week->assertOk();
        $this->assertSame('day', $week->json('data.interval'));
        $this->assertCount(7, $week->json('data.trends'));

        // A quarter: weekly.
        $quarter = $this->actingAs($this->admin)->getJson('/api/v1/analytics/sales/trends?start_date='
            .$start->toDateString().'&end_date='.$start->copy()->addDays(83)->toDateString().'&interval=auto');
        $quarter->assertOk();
        $this->assertSame('week', $quarter->json('data.interval'));
        $this->assertCount(12, $quarter->json('data.trends'));

        // An explicit interval is still honoured as asked.
        $daily = $this->actingAs($this->admin)->getJson('/api/v1/analytics/sales/trends?start_date='
            .$start->toDateString().'&end_date='.$start->copy()->addDays(83)->toDateString().'&interval=day');
        $daily->assertOk();
        $this->assertCount(84, $daily->json('data.trends'));
    }
}
