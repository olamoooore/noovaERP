<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Analytics\SalesAnalyticsService;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosOrderItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TenantTestCase;

/**
 * Issue #158: the four SalesAnalyticsService report methods used to query only
 * `pos_orders` / `pos_order_items`, silently under-reporting revenue from
 * Sales-module orders (admin / ecommerce channels). They now UNION ALL the
 * POS and the posted Sales-module revenue streams.
 */
class SalesAnalyticsServiceTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '77777777-1111-2222-3333-444444444444',
        ]));
        $this->bindTenantContext($tenant->id);

        $this->user = User::factory()->create();
        $this->customer = Customer::factory()->create([
            'name' => 'Cross Channel Customer',
        ]);
        $this->product = Product::factory()->create([
            'name' => 'Cross Channel Widget',
            'sku' => 'CCW-'.uniqid(),
            'price' => 100,
            'cost' => 40,
            'track_inventory' => false,
        ]);
    }

    private function posOrder(float $total, int $quantity = 1): PosOrder
    {
        $posOrder = PosOrder::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => 'paid',
            'is_return' => false,
            'subtotal' => $total,
            'tax_amount' => 0,
            'total' => $total,
            'paid_at' => Carbon::now(),
            'payment_method' => 'cash',
        ]);

        PosOrderItem::create([
            'pos_order_id' => $posOrder->id,
            'product_id' => $this->product->id,
            'name' => $this->product->name,
            'quantity' => $quantity,
            'unit_price' => $total / max($quantity, 1),
            'line_total' => $total,
        ]);

        return $posOrder;
    }

    private function salesOrder(float $total, string $channel = 'admin', string $status = 'confirmed', string $accountingStatus = 'not_posted'): SalesOrder
    {
        $order = SalesOrder::create([
            'sales_order_number' => 'SO-TEST-'.uniqid(),
            'customer_id' => $this->customer->id,
            'channel' => $channel,
            'status' => $status,
            'accounting_status' => $accountingStatus,
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'order_date' => Carbon::now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $this->product->id,
            'description' => 'Admin Channel Widget',
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);

        return $order;
    }

    private function filter(): ReportFilter
    {
        return new ReportFilter(
            startDate: Carbon::now()->subDay()->startOfDay(),
            endDate: Carbon::now()->addDay()->endOfDay(),
        );
    }

    /** The four report methods must aggregate POS and Sales-module revenue together. */
    public function test_reports_combine_pos_and_sales_module_revenue(): void
    {
        $this->posOrder(100.0);
        $this->salesOrder(500.0, 'admin');
        $this->salesOrder(250.0, 'ecommerce');

        $service = app(SalesAnalyticsService::class);

        $byCustomer = $service->getSalesByCustomer($this->filter());
        $this->assertSame(850.0, (float) $byCustomer['summary']['total_revenue']);
        $this->assertSame(['pos', 'admin', 'ecommerce'], $byCustomer['summary']['channels_included']);
        $this->assertCount(1, $byCustomer['data'], 'A single customer aggregates POS + sales orders.');

        $byProduct = $service->getSalesByProduct($this->filter());
        $this->assertSame(850.0, (float) $byProduct['summary']['total_revenue']);
        $this->assertSame($this->product->id, $byProduct['data'][0]['id'] ?? null);

        $trends = $service->getSalesTrends($this->filter());
        $this->assertSame(850.0, (float) ($trends['summary']['total_revenue'] ?? 0));
        $this->assertSame(3, (int) $trends['summary']['total_orders']);

        $byCategory = $service->getSalesByCategory($this->filter());
        $this->assertSame(850.0, (float) ($byCategory['summary']['total_revenue'] ?? 0));
    }

    /** Cancelled and accounting-reversed Sales orders must be excluded. */
    public function test_cancelled_and_reversed_sales_orders_do_not_contribute(): void
    {
        $this->posOrder(50.0);
        $this->salesOrder(200.0, 'admin'); // confirmed, current
        $this->salesOrder(999.0, 'admin', 'cancelled'); // must not count
        $this->salesOrder(777.0, 'admin', 'fulfilled', 'reversed'); // must not count

        $byCustomer = app(SalesAnalyticsService::class)->getSalesByCustomer($this->filter());

        // 50 (POS) + 200 (current sales) = 250; cancelled 999 and reversed 777 excluded.
        $this->assertSame(250.0, (float) $byCustomer['summary']['total_revenue'], json_encode($byCustomer));
    }

    /** POS without a customer (walk-in) must still appear in the customer report. */
    public function test_pos_walk_in_appears_as_walk_in_customer(): void
    {
        $walkIn = PosOrder::factory()->create([
            'customer_id' => null,
            'status' => 'paid',
            'is_return' => false,
            'subtotal' => 75.0, 'tax_amount' => 0, 'total' => 75.0,
            'paid_at' => Carbon::now(),
            'payment_method' => 'cash',
        ]);

        PosOrderItem::create([
            'pos_order_id' => $walkIn->id,
            'product_id' => $this->product->id,
            'name' => $this->product->name,
            'quantity' => 1,
            'unit_price' => 75.0,
            'line_total' => 75.0,
        ]);

        $this->salesOrder(300.0, 'admin');

        $byCustomer = app(SalesAnalyticsService::class)->getSalesByCustomer($this->filter());

        $names = collect($byCustomer['data'])->pluck('name')->all();
        $this->assertContains('Walk-in Customer', $names, json_encode($byCustomer));
        $this->assertContains('Cross Channel Customer', $names);
        $this->assertSame(375.0, (float) $byCustomer['summary']['total_revenue']);
        $this->assertSame(75.0, (float) $byCustomer['summary']['walk_in_sales']);
    }
}
