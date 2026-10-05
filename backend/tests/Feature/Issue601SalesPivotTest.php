<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TenantTestCase;

/**
 * Issue #601 (SALES-ODOO): Sales Analysis had four fixed tabs, each with one
 * hard-coded grouping. This suite drives the real HTTP route
 * (router → middleware → AnalyticsController → SalesAnalyticsService) for the
 * pivot endpoint: row dimensions × column dimensions, the five measures the
 * issue names (paid qty, FOC qty, net revenue, cost, margin), an expandable
 * row hierarchy and a validated dimension/measure whitelist.
 *
 * The business rules pinned here:
 *   - a pivot cell totals the same #355 revenue read model the other sales
 *     reports use (posted invoices / credit notes / POS / un-invoiced
 *     bookings), so the pivot reconciles with the fixed tabs;
 *   - paid quantity and FOC quantity split on the line's own net revenue
 *     (a line with zero net revenue is a free-of-charge line), which is how
 *     the #560 FOC line (list price, 100% discount, net 0) will present;
 *   - `margin` = net revenue − cost, where cost is quantity × the product's
 *     cost (the same source the product/category reports use);
 *   - dimensions and measures are a fixed whitelist mapped to SQL; anything
 *     else is a 422, never interpolated into the query.
 */
class Issue601SalesPivotTest extends TenantTestCase
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

        $this->admin = $this->setUpTenantAdminUser('Sales Pivot 601 Tenant');
        CompanySettings::query()->create(['base_currency' => 'USD']);

        $this->categoryA = ProductCategory::create(['name' => '601 Category A', 'is_active' => true]);
        $this->categoryB = ProductCategory::create(['name' => '601 Category B', 'is_active' => true]);

        $this->productA = Product::factory()->create([
            'name' => '601 Widget A',
            'sku' => '601-A-'.uniqid(),
            'price' => 100,
            'cost' => 40,
            'track_inventory' => false,
            'category_id' => $this->categoryA->id,
        ]);
        $this->productB = Product::factory()->create([
            'name' => '601 Widget B',
            'sku' => '601-B-'.uniqid(),
            'price' => 200,
            'cost' => 80,
            'track_inventory' => false,
            'category_id' => $this->categoryB->id,
        ]);

        $this->customerA = Customer::factory()->create(['name' => '601 Customer A']);
        $this->customerB = Customer::factory()->create(['name' => '601 Customer B']);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * One confirmed sales order with one priced line — an un-invoiced booking,
     * so the #355 read model counts it at `quantity × unit_price` net revenue.
     */
    private function sale(
        string $date,
        Customer $customer,
        Product $product,
        float $quantity,
        float $unitPrice,
        ?string $salesperson = null,
        ?string $team = null,
    ): SalesOrder {
        $lineTotal = $quantity * $unitPrice;

        $order = SalesOrder::create([
            'sales_order_number' => 'SO-601-'.uniqid(),
            'customer_id' => $customer->id,
            'channel' => 'admin',
            'status' => 'confirmed',
            'accounting_status' => 'not_posted',
            'subtotal' => $lineTotal,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $lineTotal,
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'order_date' => $date,
            'created_by' => $this->admin->id,
            'salesperson' => $salesperson,
            'sales_team' => $team,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'description' => '601 line',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => $lineTotal,
        ]);

        return $order;
    }

    /** A paid line (qty 5 × 100) plus a zero-net FOC line (qty 1) on one order. */
    private function saleWithFreeLine(string $date, Customer $customer, Product $product): SalesOrder
    {
        $order = SalesOrder::create([
            'sales_order_number' => 'SO-601-FOC-'.uniqid(),
            'customer_id' => $customer->id,
            'channel' => 'admin',
            'status' => 'confirmed',
            'accounting_status' => 'not_posted',
            'subtotal' => 500,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 500,
            'currency_code' => 'USD',
            'exchange_rate' => 1.0,
            'order_date' => $date,
            'created_by' => $this->admin->id,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'description' => '601 paid line',
            'quantity' => 5,
            'unit_price' => 100,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 500,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'description' => '601 FOC line',
            'quantity' => 1,
            'unit_price' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 0,
        ]);

        return $order;
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────

    private function pivot(array $params = [])
    {
        $query = array_merge([
            'start_date' => '2026-08-01',
            'end_date' => '2026-09-30',
        ], $params);

        return $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/pivot?'.http_build_query($query));
    }

    /** @return array<string, mixed> */
    private function pivotData(array $params = []): array
    {
        $response = $this->pivot($params);
        $response->assertOk();

        return $response->json('data') ?? [];
    }

    /** @return array<string, mixed> */
    private function rowByLabel(array $rows, string $label): array
    {
        foreach ($rows as $row) {
            if (($row['label'] ?? null) === $label) {
                return $row;
            }
        }

        $this->fail("No pivot row labelled [{$label}]. Rows: ".json_encode(array_column($rows, 'label')));
    }

    // ── The pivot matrix ──────────────────────────────────────────────────

    public function test_pivot_returns_correct_cells_and_totals_for_row_and_column_group_bys(): void
    {
        // Product A: 5 × 100 in Aug, 3 × 100 in Sep. Product B: 2 × 200 in Sep.
        $this->sale('2026-08-05', $this->customerA, $this->productA, 5, 100);
        $this->sale('2026-09-10', $this->customerA, $this->productA, 3, 100);
        $this->sale('2026-09-15', $this->customerB, $this->productB, 2, 200);

        $data = $this->pivotData([
            'row_dims' => ['product'],
            'col_dims' => ['month'],
            'measure' => ['net_revenue', 'paid_qty', 'cost', 'margin'],
        ]);

        $this->assertSame(['product'], $data['row_dims']);
        $this->assertSame(['month'], $data['col_dims']);
        $this->assertSame(['net_revenue', 'paid_qty', 'cost', 'margin'], $data['measures']);

        // One column per month, in calendar order.
        $this->assertSame([['2026-08'], ['2026-09']], array_column($data['columns'], 'key'));
        $this->assertSame('Aug 2026', $data['columns'][0]['label']);
        $this->assertSame('Sep 2026', $data['columns'][1]['label']);

        $productA = $this->rowByLabel($data['rows'], '601 Widget A');
        $productB = $this->rowByLabel($data['rows'], '601 Widget B');

        // Product A, August: revenue 500, paid qty 5, cost 5 × 40 = 200, margin 300.
        $this->assertEquals(500, $productA['values'][0]['net_revenue']);
        $this->assertEquals(5, $productA['values'][0]['paid_qty']);
        $this->assertEquals(200, $productA['values'][0]['cost']);
        $this->assertEquals(300, $productA['values'][0]['margin']);

        // Product A, September: 300 / 3 / 120 / 180.
        $this->assertEquals(300, $productA['values'][1]['net_revenue']);
        $this->assertEquals(3, $productA['values'][1]['paid_qty']);
        $this->assertEquals(120, $productA['values'][1]['cost']);
        $this->assertEquals(180, $productA['values'][1]['margin']);

        // Column 0 is August: Product B sold nothing then, so that cell is a
        // real zero rather than a shifted September value.
        $this->assertEquals(0, $productB['values'][0]['net_revenue']);
        $this->assertEquals(0, $productB['values'][0]['paid_qty']);
        $this->assertEquals(400, $productB['values'][1]['net_revenue']);
        $this->assertEquals(160, $productB['values'][1]['cost']);

        // Row totals and the grand total reconcile with the tab reports.
        $this->assertEquals(800, $productA['totals']['net_revenue']);
        $this->assertEquals(8, $productA['totals']['paid_qty']);
        $this->assertEquals(320, $productA['totals']['cost']);
        $this->assertEquals(480, $productA['totals']['margin']);
        $this->assertEquals(1200, $data['totals']['net_revenue']);
        $this->assertEquals(480, $data['totals']['cost']);
        // Three grouped rows: A×Aug, A×Sep, B×Sep; two month columns.
        $this->assertEquals(3, $data['summary']['row_count']);
        $this->assertEquals(2, $data['summary']['column_count']);
        $this->assertEquals('USD', $data['summary']['base_currency']);
    }

    public function test_pivot_separates_paid_and_foc_quantity(): void
    {
        $this->saleWithFreeLine('2026-08-05', $this->customerA, $this->productA);

        $data = $this->pivotData([
            'row_dims' => ['product'],
            'measure' => ['paid_qty', 'foc_qty', 'net_revenue', 'cost', 'margin'],
        ]);

        $productA = $this->rowByLabel($data['rows'], '601 Widget A');
        $cell = $productA['values'][0];

        // Free goods are counted in FOC qty, not in paid qty, and their cost
        // still lands in cost/margin so FOC cost is visible.
        $this->assertEquals(5, $cell['paid_qty']);
        $this->assertEquals(1, $cell['foc_qty']);
        $this->assertEquals(500, $cell['net_revenue']);
        $this->assertEquals(240, $cell['cost']);
        $this->assertEquals(260, $cell['margin']);
        $this->assertEquals(6, $productA['totals']['paid_qty'] + $productA['totals']['foc_qty']);
    }

    public function test_pivot_builds_an_expandable_hierarchy_for_multiple_row_dimensions(): void
    {
        $this->sale('2026-08-05', $this->customerA, $this->productA, 5, 100);
        $this->sale('2026-08-06', $this->customerB, $this->productB, 2, 200);

        $data = $this->pivotData([
            'row_dims' => ['category', 'product'],
            'measure' => ['net_revenue'],
        ]);

        $this->assertSame(['category', 'product'], $data['row_dims']);
        $this->assertCount(2, $data['rows'], 'Top level is the category dimension');

        $categoryA = $this->rowByLabel($data['rows'], '601 Category A');
        $this->assertEquals(0, $categoryA['depth']);
        $this->assertCount(1, $categoryA['children']);
        $this->assertSame(['601 Category A', '601 Widget A'], $categoryA['children'][0]['key']);
        $this->assertEquals(1, $categoryA['children'][0]['depth']);
        $this->assertSame([], $categoryA['children'][0]['children'], 'Product is the leaf dimension');
        // The parent carries the same aggregate as its child.
        $this->assertEquals(500, $categoryA['totals']['net_revenue']);
        $this->assertEquals(500, $categoryA['children'][0]['totals']['net_revenue']);
    }

    public function test_pivot_supports_salesperson_team_and_country_dimensions(): void
    {
        $salesperson = User::factory()->create(['name' => '601 Seller']);
        $this->customerA->update(['salesperson_id' => $salesperson->id]);

        CustomerAddress::create([
            'customer_id' => $this->customerA->id,
            'type' => 'billing',
            'label' => 'HQ',
            'country' => 'MY',
            'is_default' => true,
        ]);

        // Document salesperson/team win over the customer's assigned user.
        $this->sale('2026-08-05', $this->customerA, $this->productA, 5, 100, 'Doc Seller', 'Team Alpha');
        $this->sale('2026-08-06', $this->customerB, $this->productB, 2, 200);

        $data = $this->pivotData([
            'row_dims' => ['salesperson', 'team', 'country'],
            'measure' => ['net_revenue'],
        ]);

        $seller = $this->rowByLabel($data['rows'], 'Doc Seller');
        $this->assertEquals(500, $seller['totals']['net_revenue']);
        $team = $this->rowByLabel($seller['children'], 'Team Alpha');
        $this->assertEquals(500, $team['totals']['net_revenue']);
        $country = $this->rowByLabel($team['children'], 'MY');
        $this->assertEquals(500, $country['totals']['net_revenue']);

        // Customer B has no document salesperson, so the assigned-salesperson
        // fallback applies (none assigned) — never a dropped row.
        $this->assertNotNull($this->rowByLabel($data['rows'], 'Unassigned'));
    }

    public function test_pivot_honours_the_existing_sales_filters(): void
    {
        $this->sale('2026-08-05', $this->customerA, $this->productA, 5, 100);
        $this->sale('2026-08-06', $this->customerB, $this->productB, 2, 200);

        $data = $this->pivotData([
            'customer_id' => $this->customerA->id,
            'row_dims' => ['customer', 'product'],
            'measure' => ['net_revenue'],
        ]);

        $this->assertCount(1, $data['rows']);
        $this->assertSame('601 Customer A', $data['rows'][0]['label']);
        $this->assertEquals(500, $data['totals']['net_revenue']);
    }

    // ── The whitelist ─────────────────────────────────────────────────────

    public function test_invalid_dimension_is_rejected_with_422(): void
    {
        $this->sale('2026-08-05', $this->customerA, $this->productA, 5, 100);

        $this->pivot(['row_dims' => ['product; DROP TABLE customers; --']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['row_dims.0']);

        $this->pivot(['col_dims' => ['cost) FROM invoices; --']])
            ->assertStatus(422);
    }

    public function test_invalid_measure_is_rejected_with_422(): void
    {
        $this->pivot(['measure' => ['sum(1)']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measure.0']);
    }

    public function test_pivot_endpoint_rejects_an_unknown_filter(): void
    {
        $this->pivot(['group_by' => 'salesperson'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['filters']);
    }
}
