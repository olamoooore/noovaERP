<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Analytics\SalesAnalyticsService;
use Modules\Sales\Services\SalesOrderService;
use Tests\TenantTestCase;

/**
 * Issue #592 (P1, RP3) — getSalesByProduct() and getSalesByCategory() cast
 * every quantity with (int), so fractional quantities (0.5 box, 2.5 m,
 * 12.75 ml) are truncated while revenue stays exact: the Qty Sold column
 * disagrees with the money columns and under-reports what actually sold.
 *
 * Base-unit quantities are stored at scale 4 across the stock path (#250:
 * numeric(15,4) on stock_levels / stock_movements / products.stock_quantity),
 * on sales_order_lines (decimal(15,4), cast decimal:4) and the sales-pipeline
 * analytics already report round(quantity, 4). The report quantities must use
 * the same scale instead of (int).
 *
 * The fractional fixtures are confirmed, un-invoiced sales orders — the
 * 'booking' arm of the report union — because sales_order_lines is the report
 * arm that stores fractional base-unit quantities. Every report is read
 * through the real HTTP routes (router → middleware → controller → service).
 */
class HR592SalesReportDecimalQuantityTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Decimal Qty 592 Tenant');

        CompanySettings::query()->create(['base_currency' => 'MYR']);

        $this->category = ProductCategory::create([
            'name' => 'HR592 Units',
            'is_active' => true,
        ]);

        $this->customer = Customer::factory()->create([
            'name' => 'HR592 Customer',
            'is_active' => true,
        ]);
    }

    private function product(string $name, string $unit): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'sku' => 'HR592-'.uniqid(),
            'category_id' => $this->category->id,
            'unit' => $unit,
            'price' => 1000,
            'cost' => 100,
            'track_inventory' => false,
        ]);
    }

    /**
     * A confirmed, un-invoiced sales order (booking) carrying the given
     * fractional base-unit quantities on real sales_order_lines.
     *
     * @param  array<int, array{product: Product, quantity: float}>  $lines
     */
    private function booking(array $lines): void
    {
        $order = app(SalesOrderService::class)->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'created_by' => $this->admin->id,
            'lines' => array_map(fn (array $line) => [
                'product_id' => $line['product']->id,
                'description' => $line['product']->name,
                'quantity' => $line['quantity'],
                'unit_price' => 1000,
                'price_mode' => 'carried',
                'tax_rate' => 0,
            ], $lines),
        ]);

        DB::table('sales_orders')->where('id', $order->id)->update([
            'status' => 'confirmed',
            'order_date' => now()->toDateString(),
        ]);
    }

    private function periodQuery(): string
    {
        return '?start_date='.now()->toDateString().'&end_date='.now()->toDateString();
    }

    private function productRow(Product $product): array
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/by-product'.$this->periodQuery());
        $response->assertOk();

        $row = collect($response->json('data.products'))->firstWhere('id', $product->id);
        $this->assertNotNull($row, "Product {$product->name} is missing from the by-product report");

        return $row;
    }

    private function categoryRow(): array
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/by-category'.$this->periodQuery());
        $response->assertOk();

        $row = collect($response->json('data.categories'))
            ->firstWhere('category_id', $this->category->id);
        $this->assertNotNull($row, 'The category is missing from the by-category report');

        return $row;
    }

    /**
     * Acceptance: two lines of 0.5 report 1.0, not 0. Each product row must
     * show its 0.5 and the category must total 1.0 — the (int) cast reported
     * each 0.5 product line as 0 while revenue stayed exact.
     */
    public function test_two_half_unit_lines_report_one_unit_not_zero(): void
    {
        $halfBox = $this->product('HR592 Half Box', 'box');
        $halfMetre = $this->product('HR592 Half Metre', 'm');

        $this->booking([
            ['product' => $halfBox, 'quantity' => 0.5],
            ['product' => $halfMetre, 'quantity' => 0.5],
        ]);

        $boxRow = $this->productRow($halfBox);
        $this->assertEqualsWithDelta(0.5, (float) $boxRow['total_quantity'], 0.00005, 'by-product total_quantity');
        $this->assertEqualsWithDelta(0.5, (float) $boxRow['quantity_sold'], 0.00005, 'by-product quantity_sold');

        $metreRow = $this->productRow($halfMetre);
        $this->assertEqualsWithDelta(0.5, (float) $metreRow['quantity_sold'], 0.00005, 'by-product quantity_sold');

        $category = $this->categoryRow();
        $this->assertEqualsWithDelta(
            1.0,
            (float) $category['quantity_sold'],
            0.00005,
            'Two 0.5-unit lines must report 1.0, not 0'
        );
    }

    /**
     * Acceptance: quantities are reported at the stored decimal scale instead
     * of being truncated to whole units (the 2.5 m and 12.75 ml cases from the
     * issue) — and the category total sums the decimals, not the truncations.
     */
    public function test_fractional_quantities_are_reported_at_decimal_scale(): void
    {
        $metres = $this->product('HR592 Cable', 'm');
        $millilitres = $this->product('HR592 Reagent', 'ml');

        $this->booking([
            ['product' => $metres, 'quantity' => 2.5],
            ['product' => $millilitres, 'quantity' => 12.75],
        ]);

        $this->assertEqualsWithDelta(2.5, (float) $this->productRow($metres)['quantity_sold'], 0.00005);
        $this->assertEqualsWithDelta(12.75, (float) $this->productRow($millilitres)['quantity_sold'], 0.00005);

        $category = $this->categoryRow();
        $this->assertEqualsWithDelta(15.25, (float) $category['quantity_sold'], 0.00005);
    }

    /**
     * The by-product summary's total_quantity_sold carries the same decimal
     * quantity. The analytics HTTP envelope does not surface the summary, so
     * it is read from the same un-mocked service the route calls.
     */
    public function test_summary_total_quantity_sold_is_decimal(): void
    {
        $box = $this->product('HR592 Summary Box', 'box');

        $this->booking([
            ['product' => $box, 'quantity' => 0.5],
            ['product' => $box, 'quantity' => 0.5],
            ['product' => $box, 'quantity' => 2.5],
        ]);

        $report = app(SalesAnalyticsService::class)->getSalesByProduct(new ReportFilter(
            startDate: now()->startOfDay(),
            endDate: now()->endOfDay(),
        ));

        $this->assertEqualsWithDelta(
            3.5,
            (float) $report['summary']['total_quantity_sold'],
            0.00005,
            'The summary quantity must not truncate 0.5 + 0.5 + 2.5 to 3'
        );
    }
}
