<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Services\SalesOrderService;
use Tests\TenantTestCase;

/**
 * MRD pricelist-multicurrency FR-8.1 — sales analytics gain an order-currency
 * dimension: per-currency revenue with the snapshot-rate base equivalent, and
 * a currency filter that excludes currencies-less POS rows.
 */
class SalesAnalyticsCurrencyTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = (string) \Illuminate\Support\Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $tenantId], [
            'name' => 'Analytics Currency Tenant',
            'email' => 'ac-'.substr(md5($tenantId), 0, 8).'@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = $this->setUpTenantAdminUser('Analytics Currency Tenant');
        CompanySettings::query()->create(['base_currency' => 'SGD']);
        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create(['price' => 100, 'cost' => 40, 'track_inventory' => false]);
    }

    private function confirmedOrder(float $unitPrice, string $currency, float $rate): void
    {
        $order = app(SalesOrderService::class)->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'currency_code' => $currency,
            'exchange_rate' => $rate,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Analytics line',
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'price_mode' => 'carried',
                'tax_rate' => 0,
            ]],
        ]);

        DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'confirmed']);
    }

    public function test_sales_by_currency_splits_revenue_with_base_equivalents(): void
    {
        $this->confirmedOrder(100, 'USD', 1.35);
        $this->confirmedOrder(100, 'SGD', 1.0);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/analytics/sales/by-currency');
        $response->assertOk();

        $buckets = collect($response->json('data.currencies'));

        $usd = $buckets->firstWhere('currency_code', 'USD');
        $this->assertNotNull($usd, 'USD bucket present');
        $this->assertSame(100.0, round((float) $usd['total_revenue'], 2), 'document-currency revenue');
        $this->assertSame(135.0, round((float) $usd['base_revenue'], 2), 'base equivalent at the SO snapshot rate');

        $sgd = $buckets->firstWhere('currency_code', 'SGD');
        $this->assertSame(100.0, round((float) $sgd['base_revenue'], 2));

        $this->assertSame(
            235.0,
            round((float) $response->json('data.summary.total_base_revenue'), 2)
        );
    }

    public function test_currency_filter_restricts_the_analytics_scope(): void
    {
        $this->confirmedOrder(100, 'USD', 1.35);
        $this->confirmedOrder(100, 'SGD', 1.0);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/analytics/sales/by-currency?currency_code=USD');
        $response->assertOk();

        $buckets = collect($response->json('data.currencies'));
        $this->assertCount(1, $buckets, 'filter excludes other currencies');
        $this->assertSame('USD', $buckets->first()['currency_code']);
        $this->assertSame(135.0, round((float) $response->json('data.summary.total_base_revenue'), 2));

        // The same filter applies to the trend series (one shared SQL union).
        $trends = $this->actingAs($this->admin)->getJson('/api/v1/analytics/sales/trends?currency_code=USD&interval=day');
        $trends->assertOk();
        $total = collect($trends->json('data.trends'))->sum(fn ($row) => (float) ($row['revenue'] ?? $row['total_revenue'] ?? 0));
        $this->assertSame(100.0, round($total, 2), 'trends respect the currency filter (USD document revenue only)');
    }
}
