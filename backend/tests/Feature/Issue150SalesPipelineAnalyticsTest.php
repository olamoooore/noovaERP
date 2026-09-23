<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #150 — sales-pipeline analytics (pre-invoice): orders by status,
 * quotation win rate + aging, expected revenue by month, salesperson
 * performance and product mix.
 */
class Issue150SalesPipelineAnalyticsTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '15015015-0151-5253-5455-565758595051',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['sales_orders', 'sales_order_lines', 'quotations', 'customers', 'products']);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['name' => 'Pipeline Rep']);
        $this->user->assignRole('admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create();
    }

    private function order(string $status, float $total, string $date = '2026-09-10'): SalesOrder
    {
        $order = SalesOrder::create([
            'sales_order_number' => 'SO-150-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'status' => $status,
            'order_date' => $date,
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'created_by' => $this->user->id,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Pipeline line',
            'product_id' => $this->product->id,
            'quantity' => 2,
            'unit_price' => $total / 2,
            'tax_rate' => 0,
            'line_total' => $total,
        ]);

        return $order;
    }

    private function quotation(string $status, float $total, string $date): void
    {
        Quotation::create([
            'quotation_number' => 'QT-150-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'quotation_date' => $date,
            'status' => $status,
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_pipeline_analytics_reports_statuses_win_rate_and_forecast(): void
    {
        $this->order('draft', 100, '2026-09-05');
        $this->order('confirmed', 200, '2026-09-10');
        $this->order('invoiced', 300, '2026-09-15');
        $this->order('cancelled', 50, '2026-09-20');
        // Outside the range.
        $this->order('draft', 9999, '2026-07-01');

        $this->quotation('confirmed', 200, '2026-09-01');
        $this->quotation('cancelled', 100, '2026-09-02');
        $this->quotation('sent', 150, now()->subDays(3)->toDateString());
        $this->quotation('sent', 150, '2026-08-15');

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/sales/analytics/pipeline?from_date=2026-08-01&to_date=2026-09-30');

        $response->assertOk();
        $data = $response->json('data');

        // Orders by status (period-scoped).
        $statuses = collect($data['orders_by_status'])->keyBy('status');
        $this->assertSame(1, $statuses['draft']['count']);
        $this->assertSame(100.0, (float) $statuses['draft']['value']);
        $this->assertSame(1, $statuses['invoiced']['count']);

        // Open pipeline excludes invoiced/cancelled.
        $this->assertSame(2, $data['pipeline']['open_orders']);
        $this->assertSame(300.0, (float) $data['pipeline']['open_value']);
        $this->assertSame(300.0, (float) $data['pipeline']['won_value']);

        // Quotation win rate + aging buckets.
        $this->assertSame(1, $data['quotations']['won']);
        $this->assertSame(1, $data['quotations']['lost']);
        $this->assertSame(50.0, (float) $data['quotations']['win_rate_pct']);
        $this->assertSame(1, $data['quotations']['aging']['0_7_days']);
        $this->assertSame(1, $data['quotations']['aging']['31_60_days']);

        // Expected revenue by month for the open pipeline.
        $forecast = collect($data['expected_revenue_by_month'])->firstWhere('month', '2026-09');
        $this->assertNotNull($forecast);
        $this->assertSame(300.0, (float) $forecast['value']);

        // Salesperson performance + product mix.
        $this->assertSame('Pipeline Rep', $data['salespeople'][0]['name']);
        $this->assertSame(4, $data['salespeople'][0]['orders']);
        $this->assertSame(1, $data['salespeople'][0]['won']);
        $this->assertSame(4.0, (float) $data['product_mix'][0]['quantity']);
    }
}
