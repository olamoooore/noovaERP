<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TenantTestCase;

/**
 * Issue #360 (SALES-DASH, P2, S12): the by-product report counted `COUNT(*)`
 * line rows as "orders", so a product on two lines of one order counted as two
 * orders; product-less lines (service / description lines) collapsed into one
 * unnamed group the dashboard rendered as "Unknown"; and note / section lines
 * were counted too.
 *
 * Acceptance criteria:
 *   - order_count is COUNT(DISTINCT order_ref), note and section lines excluded;
 *   - product-less revenue is labelled explicitly ("Services / unlinked lines").
 *
 * Driven through the real HTTP route (router → middleware → controller →
 * SalesAnalyticsService).
 */
class Issue360SalesByProductOrderCountTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Sales By Product 360 Tenant');
        CompanySettings::query()->create(['base_currency' => 'MYR']);
        $this->customer = Customer::factory()->create(['name' => '360 Customer']);
        $this->product = Product::factory()->create([
            'name' => '360 Widget',
            'sku' => '360-'.uniqid(),
            'price' => 100,
            'cost' => 40,
            'track_inventory' => false,
        ]);
    }

    private function order(float $total): SalesOrder
    {
        return SalesOrder::create([
            'sales_order_number' => 'SO-360-'.uniqid(),
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'status' => 'confirmed',
            'accounting_status' => 'not_posted',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'order_date' => '2026-10-01',
            'created_by' => $this->admin->id,
        ]);
    }

    private function line(SalesOrder $order, ?int $productId, string $type, float $qty, float $price): void
    {
        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'line_type' => $type,
            'product_id' => $productId,
            'description' => "360 {$type} line",
            'quantity' => $qty,
            'unit_price' => $price,
            'line_total' => $qty * $price,
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function products(): \Illuminate\Support\Collection
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/analytics/sales/by-product?start_date=2026-10-01&end_date=2026-10-01');
        $response->assertOk();

        return collect($response->json('data.products') ?? []);
    }

    public function test_order_count_counts_distinct_orders_not_lines(): void
    {
        // Order A carries the product on two separate lines; order B once.
        $orderA = $this->order(300);
        $this->line($orderA, $this->product->id, 'product', 1, 100);
        $this->line($orderA, $this->product->id, 'product', 2, 100);

        $orderB = $this->order(100);
        $this->line($orderB, $this->product->id, 'product', 1, 100);

        $row = $this->products()->firstWhere('id', $this->product->id);

        $this->assertNotNull($row, 'The product must appear in the by-product report.');
        $this->assertSame(4.0, (float) $row['total_quantity']);
        $this->assertArrayHasKey('order_count', $row, 'The by-product row must expose its order count.');
        $this->assertSame(
            2,
            (int) $row['order_count'],
            'Two orders contain the product; three line rows must not read as three orders.'
        );
    }

    public function test_product_less_lines_are_labelled_and_note_section_lines_excluded(): void
    {
        // Order A: a product line and a product-less service line.
        $orderA = $this->order(150);
        $this->line($orderA, $this->product->id, 'product', 1, 100);
        $this->line($orderA, null, 'service', 1, 50);

        // Order B: a product line plus note and section lines (qty 0, total 0).
        $orderB = $this->order(100);
        $this->line($orderB, $this->product->id, 'product', 1, 100);
        $this->line($orderB, null, 'section', 0, 0);
        $this->line($orderB, null, 'note', 0, 0);

        $rows = $this->products();

        $this->assertNull(
            $rows->first(fn ($row) => $row['name'] === null || $row['name'] === '' || $row['name'] === 'Unknown'),
            'No row may be left unnamed (rendered as "Unknown").'
        );

        $unlinked = $rows->firstWhere('name', 'Services / unlinked lines');
        $this->assertNotNull($unlinked, 'Product-less revenue must be labelled "Services / unlinked lines".');
        $this->assertSame(50.0, (float) $unlinked['total_revenue']);
        $this->assertSame(1.0, (float) $unlinked['total_quantity']);
        $this->assertSame(
            1,
            (int) ($unlinked['order_count'] ?? -1),
            'Only order A has a product-less revenue line; order B\'s note/section lines must not count.'
        );

        $product = $rows->firstWhere('id', $this->product->id);
        $this->assertSame(2, (int) ($product['order_count'] ?? -1));
    }
}
