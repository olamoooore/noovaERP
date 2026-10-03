<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TenantTestCase;

/**
 * Issue #595 (SALES-RPT, P2, RP6): ReportFilter carried compareStartDate /
 * compareEndDate since the beginning but SalesAnalyticsService never read
 * them, and (#593's unknown-filter guard) the analytics endpoints rejected the
 * parameters outright. No sales tab could show a "vs previous period" or
 * "vs same period last year" comparison.
 *
 * This suite drives the real HTTP route (router → middleware →
 * AnalyticsController → SalesAnalyticsService). The comparison window is
 * evaluated through the SAME read model (#395 line-item union) and the same
 * zero-fill/aggregation path as the primary window, so the numbers here are
 * the numbers the report shows.
 *
 * Delta semantics pinned below: delta = current − previous (missing previous
 * row counts as 0) and delta_percent = delta / |previous| × 100, or NULL when
 * previous is 0 — never a division by zero, never a fabricated 100%.
 */
class Issue595SalesComparisonTest extends TenantTestCase
{
    private User $admin;

    private Customer $customerA;

    private Customer $customerB;

    private Product $productA;

    private Product $productB;

    private ProductCategory $categoryA;

    private ProductCategory $categoryB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Sales Comparison 595 Tenant');
        CompanySettings::query()->create(['base_currency' => 'MYR']);

        $this->customerA = Customer::factory()->create(['name' => '595 Customer A']);
        $this->customerB = Customer::factory()->create(['name' => '595 Customer B']);

        $this->categoryA = ProductCategory::create(['name' => '595 Category A', 'is_active' => true]);
        $this->categoryB = ProductCategory::create(['name' => '595 Category B', 'is_active' => true]);

        $this->productA = Product::factory()->create([
            'name' => '595 Widget A',
            'sku' => '595-A-'.uniqid(),
            'price' => 100,
            'cost' => 40,
            'track_inventory' => false,
            'category_id' => $this->categoryA->id,
        ]);
        $this->productB = Product::factory()->create([
            'name' => '595 Widget B',
            'sku' => '595-B-'.uniqid(),
            'price' => 200,
            'cost' => 80,
            'track_inventory' => false,
            'category_id' => $this->categoryB->id,
        ]);
    }

    private function saleOn(
        string $date,
        Customer $customer,
        Product $product,
        float $total
    ): SalesOrder {
        $order = SalesOrder::create([
            'sales_order_number' => 'SO-595-'.uniqid(),
            'customer_id' => $customer->id,
            'channel' => 'admin',
            'status' => 'confirmed',
            'accounting_status' => 'not_posted',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'currency_code' => 'MYR',
            'exchange_rate' => 1.0,
            'order_date' => $date,
            'created_by' => $this->admin->id,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'description' => '595 line',
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);

        return $order;
    }

    private function report(string $endpoint, array $params = [])
    {
        return $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/'.$endpoint.'?'.http_build_query($params));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(\Illuminate\Testing\TestResponse $response, string $key, string $field, mixed $value): array
    {
        foreach ($response->json("data.{$key}") ?? [] as $row) {
            if ((string) ($row[$field] ?? '') === (string) $value) {
                return $row;
            }
        }

        $this->fail("No {$key} row with {$field}={$value} in: ".json_encode($response->json("data.{$key}")));
    }

    // ── Backward compatibility ────────────────────────────────────────────

    public function test_without_a_comparison_the_response_is_unchanged(): void
    {
        $this->saleOn('2026-10-07', $this->customerA, $this->productA, 300);

        $response = $this->report('by-product', [
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
        ]);
        $response->assertOk();

        $this->assertArrayNotHasKey('comparison', $response->json('data') ?? []);
        $row = $response->json('data.products.0') ?? [];
        $this->assertArrayNotHasKey('total_revenue_previous', $row);
        $this->assertArrayNotHasKey('total_revenue_delta', $row);
        $this->assertArrayNotHasKey('total_revenue_delta_percent', $row);
        $this->assertSame(300.0, (float) $row['total_revenue']);
    }

    // ── previous period ───────────────────────────────────────────────────

    public function test_previous_period_derives_an_equal_length_window_and_returns_delta_and_percent(): void
    {
        // Primary window 5–9 Oct (5 days); the previous window of equal length
        // is 30 Sep – 4 Oct.
        $this->saleOn('2026-10-07', $this->customerA, $this->productA, 300);
        $this->saleOn('2026-10-02', $this->customerA, $this->productA, 200);

        $response = $this->report('by-product', [
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
            'comparison' => 'previous_period',
        ]);
        $response->assertOk();

        $this->assertSame('previous_period', $response->json('data.comparison.mode'));
        $this->assertSame('2026-09-30', $response->json('data.comparison.period.start_date'));
        $this->assertSame('2026-10-04', $response->json('data.comparison.period.end_date'));

        $row = $response->json('data.products.0');
        $this->assertSame(300.0, (float) $row['total_revenue']);
        $this->assertSame(200.0, (float) $row['total_revenue_previous']);
        $this->assertSame(100.0, (float) $row['total_revenue_delta']);
        $this->assertSame(50.0, (float) $row['total_revenue_delta_percent']);

        // The report summary is compared too, so a header/total can show the
        // same Δ/Δ% without re-summing rows.
        $this->assertSame(200.0, (float) $response->json('data.comparison.summary.total_revenue.previous'));
        $this->assertSame(100.0, (float) $response->json('data.comparison.summary.total_revenue.delta'));
        $this->assertSame(50.0, (float) $response->json('data.comparison.summary.total_revenue.delta_percent'));
    }

    public function test_a_zero_previous_period_yields_the_delta_but_a_null_percent(): void
    {
        $this->saleOn('2026-10-07', $this->customerA, $this->productA, 300);

        $response = $this->report('by-product', [
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
            'comparison' => 'previous_period',
        ]);
        $response->assertOk();

        $row = $response->json('data.products.0');
        $this->assertSame(0.0, (float) $row['total_revenue_previous']);
        $this->assertSame(300.0, (float) $row['total_revenue_delta']);
        $this->assertNull(
            $row['total_revenue_delta_percent'],
            'A zero previous value has no percentage change — it must be null, not 0 or 100.'
        );
    }

    // ── same period last year ─────────────────────────────────────────────

    public function test_same_period_last_year_compares_the_same_calendar_window(): void
    {
        $this->saleOn('2026-10-10', $this->customerA, $this->productA, 500);
        $this->saleOn('2025-10-10', $this->customerA, $this->productA, 250);

        $response = $this->report('by-product', [
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
            'comparison' => 'same_period_last_year',
        ]);
        $response->assertOk();

        $this->assertSame('same_period_last_year', $response->json('data.comparison.mode'));
        $this->assertSame('2025-10-01', $response->json('data.comparison.period.start_date'));
        $this->assertSame('2025-10-31', $response->json('data.comparison.period.end_date'));

        $row = $response->json('data.products.0');
        $this->assertSame(250.0, (float) $row['total_revenue_previous']);
        $this->assertSame(250.0, (float) $row['total_revenue_delta']);
        $this->assertSame(100.0, (float) $row['total_revenue_delta_percent']);
    }

    // ── custom range ──────────────────────────────────────────────────────

    public function test_custom_range_is_used_and_bare_compare_dates_imply_custom(): void
    {
        $this->saleOn('2026-10-07', $this->customerA, $this->productA, 300);
        $this->saleOn('2026-08-15', $this->customerA, $this->productA, 50);

        $custom = $this->report('by-product', [
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
            'comparison' => 'custom',
            'compare_start_date' => '2026-08-01',
            'compare_end_date' => '2026-08-31',
        ]);
        $custom->assertOk();
        $this->assertSame('custom', $custom->json('data.comparison.mode'));
        $this->assertSame('2026-08-01', $custom->json('data.comparison.period.start_date'));
        $this->assertSame('2026-08-31', $custom->json('data.comparison.period.end_date'));
        $this->assertSame(50.0, (float) $custom->json('data.products.0.total_revenue_previous'));
        $this->assertSame(250.0, (float) $custom->json('data.products.0.total_revenue_delta'));
        $this->assertSame(500.0, (float) $custom->json('data.products.0.total_revenue_delta_percent'));

        // ReportFilter already accepted the two dates; supplying them without
        // a mode is still a custom comparison, not a silently dropped filter.
        $bareDates = $this->report('by-product', [
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
            'compare_start_date' => '2026-08-01',
            'compare_end_date' => '2026-08-31',
        ]);
        $bareDates->assertOk();
        $this->assertSame('custom', $bareDates->json('data.comparison.mode'));
        $this->assertSame(50.0, (float) $bareDates->json('data.products.0.total_revenue_previous'));
    }

    // ── every tab ─────────────────────────────────────────────────────────

    public function test_every_sales_tab_returns_comparison_values(): void
    {
        $this->saleOn('2026-10-01', $this->customerA, $this->productA, 100);
        $this->saleOn('2026-10-03', $this->customerB, $this->productB, 200);
        $this->saleOn('2026-09-28', $this->customerA, $this->productA, 40);
        $this->saleOn('2026-09-30', $this->customerB, $this->productB, 80);

        $params = [
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-03',
            'comparison' => 'previous_period',
        ];

        // By customer: matched by customer id.
        $byCustomer = $this->report('by-customer', $params);
        $byCustomer->assertOk();
        $this->assertSame('2026-09-28', $byCustomer->json('data.comparison.period.start_date'));
        $rowA = $this->rowFor($byCustomer, 'customers', 'id', $this->customerA->id);
        $this->assertSame(100.0, (float) $rowA['total_revenue']);
        $this->assertSame(40.0, (float) $rowA['total_revenue_previous']);
        $this->assertSame(60.0, (float) $rowA['total_revenue_delta']);
        $this->assertSame(150.0, (float) $rowA['total_revenue_delta_percent']);
        $rowB = $this->rowFor($byCustomer, 'customers', 'id', $this->customerB->id);
        $this->assertSame(200.0, (float) $rowB['total_revenue']);
        $this->assertSame(80.0, (float) $rowB['total_revenue_previous']);
        $this->assertSame(150.0, (float) $rowB['total_revenue_delta_percent']);

        // By category: matched by category id.
        $byCategory = $this->report('by-category', $params);
        $byCategory->assertOk();
        $rowCat = $this->rowFor($byCategory, 'categories', 'category_id', $this->categoryA->id);
        $this->assertSame(100.0, (float) $rowCat['revenue']);
        $this->assertSame(40.0, (float) $rowCat['revenue_previous']);
        $this->assertSame(60.0, (float) $rowCat['revenue_delta']);
        $this->assertSame(150.0, (float) $rowCat['revenue_delta_percent']);

        // By currency: matched by currency code.
        $byCurrency = $this->report('by-currency', $params);
        $byCurrency->assertOk();
        $currency = $this->rowFor($byCurrency, 'currencies', 'currency_code', 'MYR');
        $this->assertSame(300.0, (float) $currency['base_revenue']);
        $this->assertSame(120.0, (float) $currency['base_revenue_previous']);
        $this->assertSame(180.0, (float) $currency['base_revenue_delta']);
        $this->assertSame(150.0, (float) $currency['base_revenue_delta_percent']);

        // Trends: zero-filled periods, matched positionally (primary day 1 ↔
        // comparison day 1) — which is what "previous period" means on a
        // timeline; period labels differ by construction.
        $trends = $this->report('trends', $params + ['interval' => 'day']);
        $trends->assertOk();
        $rows = $trends->json('data.trends');
        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03'], array_column($rows, 'period'));
        $this->assertSame(100.0, (float) $rows[0]['revenue']);
        $this->assertSame(40.0, (float) $rows[0]['revenue_previous']);
        $this->assertSame(60.0, (float) $rows[0]['revenue_delta']);
        $this->assertSame(150.0, (float) $rows[0]['revenue_delta_percent']);
        $this->assertSame(0.0, (float) $rows[1]['revenue']);
        $this->assertSame(0.0, (float) $rows[1]['revenue_previous']);
        $this->assertSame(0.0, (float) $rows[1]['revenue_delta']);
        $this->assertNull($rows[1]['revenue_delta_percent']);
        $this->assertSame(200.0, (float) $rows[2]['revenue']);
        $this->assertSame(80.0, (float) $rows[2]['revenue_previous']);
        $this->assertSame(120.0, (float) $rows[2]['revenue_delta']);
    }

    // ── the comparison range reuses the #593 filters ──────────────────────

    public function test_comparison_range_is_narrowed_by_the_same_dimension_filters(): void
    {
        $this->saleOn('2026-10-07', $this->customerA, $this->productA, 100);
        $this->saleOn('2026-10-07', $this->customerB, $this->productB, 200);
        $this->saleOn('2026-10-02', $this->customerA, $this->productA, 40);
        $this->saleOn('2026-10-02', $this->customerB, $this->productB, 20);

        $response = $this->report('by-customer', [
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
            'comparison' => 'previous_period',
            'customer_id' => $this->customerA->id,
        ]);
        $response->assertOk();

        $rows = $response->json('data.customers');
        $this->assertCount(1, $rows, 'customer_id must narrow the comparison range too');
        $this->assertSame($this->customerA->id, $rows[0]['id']);
        $this->assertSame(100.0, (float) $rows[0]['total_revenue']);
        $this->assertSame(40.0, (float) $rows[0]['total_revenue_previous']);
        $this->assertSame(60.0, (float) $rows[0]['total_revenue_delta']);
        $this->assertSame(150.0, (float) $rows[0]['total_revenue_delta_percent']);
    }

    // ── invalid input ─────────────────────────────────────────────────────

    public function test_unknown_modes_and_incomplete_custom_ranges_are_rejected_with_422(): void
    {
        $this->saleOn('2026-10-07', $this->customerA, $this->productA, 300);

        $this->report('by-customer', [
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
            'comparison' => 'fortnight',
        ])->assertStatus(422);

        $this->report('by-customer', [
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
            'comparison' => 'custom',
        ])->assertStatus(422);

        $this->report('by-customer', [
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
            'comparison' => 'previous_period',
            'compare_start_date' => '2026-08-01',
            'compare_end_date' => '2026-08-31',
        ])->assertStatus(422);
    }
}
