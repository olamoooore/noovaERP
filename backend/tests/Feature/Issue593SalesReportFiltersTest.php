<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\TagService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosOrderItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TenantTestCase;

/**
 * Issue #593 (SALES-RPT, P1): the sales-analytics endpoints accepted a filter
 * set on `ReportFilter` (customer, product, category, legal entity) that the
 * controller validation silently dropped and `SalesAnalyticsService` never
 * read, so every "filtered" report returned the whole period. Salesperson and
 * channel had no filter at all, an unknown query parameter was ignored rather
 * than rejected, and a tag filter quietly turned the POS arm of the revenue
 * union into `AND 1 = 0` with nothing on the response saying so.
 *
 * Every test here drives the REAL HTTP route (router → middleware →
 * AnalyticsController → SalesAnalyticsService) and asserts the business rule,
 * not today's behaviour.
 */
class Issue593SalesReportFiltersTest extends TenantTestCase
{
    private User $admin;

    private User $salespersonA;

    private User $salespersonB;

    private Customer $customerA;

    private Customer $customerB;

    private Product $productA;

    private Product $productB;

    private ProductCategory $categoryA;

    private ProductCategory $categoryB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->setUpTenantAdminUser('Sales Filters Tenant');
        CompanySettings::query()->create(['base_currency' => 'USD']);

        $this->salespersonA = User::factory()->create(['name' => 'Sam Seller']);
        $this->salespersonB = User::factory()->create(['name' => 'Sara Seller']);

        $this->categoryA = ProductCategory::create(['name' => 'Filter Category A', 'is_active' => true]);
        $this->categoryB = ProductCategory::create(['name' => 'Filter Category B', 'is_active' => true]);

        $this->productA = Product::factory()->create([
            'name' => 'Filter Widget A',
            'sku' => 'FWA-'.uniqid(),
            'price' => 100,
            'cost' => 40,
            'track_inventory' => false,
            'category_id' => $this->categoryA->id,
        ]);
        $this->productB = Product::factory()->create([
            'name' => 'Filter Widget B',
            'sku' => 'FWB-'.uniqid(),
            'price' => 200,
            'cost' => 80,
            'track_inventory' => false,
            'category_id' => $this->categoryB->id,
        ]);

        $this->customerA = Customer::factory()->create([
            'name' => 'Filter Customer A',
            'salesperson_id' => $this->salespersonA->id,
        ]);
        $this->customerB = Customer::factory()->create([
            'name' => 'Filter Customer B',
            'salesperson_id' => $this->salespersonB->id,
        ]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function adminSale(
        Customer $customer,
        Product $product,
        float $total,
        string $channel = 'admin',
        ?int $legalEntityId = null
    ): SalesOrder {
        $order = SalesOrder::create([
            'sales_order_number' => 'SO-593-'.uniqid(),
            'customer_id' => $customer->id,
            'channel' => $channel,
            'status' => 'confirmed',
            'accounting_status' => 'not_posted',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'order_date' => Carbon::now()->toDateString(),
            'created_by' => $this->admin->id,
        ]);

        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'description' => '593 line',
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);

        if ($legalEntityId !== null) {
            DB::table('sales_orders')->where('id', $order->id)->update(['legal_entity_id' => $legalEntityId]);
        }

        return $order;
    }

    private function posSale(
        Customer $customer,
        Product $product,
        float $total,
        ?int $legalEntityId = null
    ): PosOrder {
        $order = PosOrder::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'paid',
            'is_return' => false,
            'subtotal' => $total,
            'tax_amount' => 0,
            'total' => $total,
            'currency' => 'USD',
            'paid_at' => Carbon::now(),
            'payment_method' => 'cash',
        ]);

        PosOrderItem::create([
            'pos_order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);

        if ($legalEntityId !== null) {
            DB::table('pos_orders')->where('id', $order->id)->update(['legal_entity_id' => $legalEntityId]);
        }

        return $order;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function twoEntities(): array
    {
        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => '593 Entity Group',
            'code' => 'E593',
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $a = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => '593 Entity A', 'code' => 'E593A',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $b = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => '593 Entity B', 'code' => 'E593B',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$a, $b];
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────

    private function range(): array
    {
        return [
            'start_date' => Carbon::now()->subDay()->toDateString(),
            'end_date' => Carbon::now()->addDay()->toDateString(),
        ];
    }

    private function analyticsRequest(string $endpoint, array $params = [], array $headers = [])
    {
        $url = '/api/v1/analytics/sales/'.$endpoint.'?'.http_build_query(array_merge($this->range(), $params));

        return $this->actingAs($this->admin)->withHeaders($headers)->getJson($url);
    }

    private function rows(string $endpoint, string $key, array $params = [], array $headers = []): \Illuminate\Support\Collection
    {
        $response = $this->analyticsRequest($endpoint, $params, $headers);
        $response->assertOk();

        return collect($response->json("data.{$key}") ?? []);
    }

    private function revenue(\Illuminate\Support\Collection $rows): float
    {
        return round((float) $rows->sum(fn ($row) => (float) ($row['total_revenue'] ?? $row['revenue'] ?? 0)), 2);
    }

    // ── The six filters change the result set ─────────────────────────────

    public function test_customer_filter_is_applied_server_side(): void
    {
        $this->adminSale($this->customerA, $this->productA, 100);
        $this->adminSale($this->customerB, $this->productB, 500);

        $rows = $this->rows('by-customer', 'customers', ['customer_id' => $this->customerA->id]);

        $this->assertCount(1, $rows, 'customer_id must narrow sales-by-customer to that customer');
        $this->assertSame($this->customerA->id, $rows->first()['id']);
        $this->assertSame(100.0, $this->revenue($rows));
    }

    public function test_product_filter_is_applied_server_side(): void
    {
        $this->adminSale($this->customerA, $this->productA, 100);
        $this->adminSale($this->customerA, $this->productB, 500);

        $rows = $this->rows('by-product', 'products', ['product_id' => $this->productA->id]);

        $this->assertCount(1, $rows, 'product_id must narrow sales-by-product to that product');
        $this->assertSame($this->productA->id, $rows->first()['id']);
        $this->assertSame(100.0, $this->revenue($rows));
    }

    public function test_category_filter_is_applied_server_side(): void
    {
        $this->adminSale($this->customerA, $this->productA, 100);
        $this->adminSale($this->customerA, $this->productB, 500);

        $rows = $this->rows('by-category', 'categories', ['category_id' => $this->categoryA->id]);

        $this->assertCount(1, $rows, 'category_id must narrow sales-by-category to that category');
        $this->assertSame($this->categoryA->id, $rows->first()['category_id']);
        $this->assertSame(100.0, round((float) $rows->first()['revenue'], 2));
    }

    public function test_salesperson_filter_is_applied_server_side(): void
    {
        $this->adminSale($this->customerA, $this->productA, 100);
        $this->adminSale($this->customerB, $this->productB, 500);

        $rows = $this->rows('by-customer', 'customers', ['salesperson_id' => $this->salespersonA->id]);

        $this->assertCount(1, $rows, 'salesperson_id must keep only the customers that salesperson owns');
        $this->assertSame($this->customerA->id, $rows->first()['id']);
        $this->assertSame(100.0, $this->revenue($rows));
    }

    public function test_channel_filter_is_applied_server_side(): void
    {
        $this->posSale($this->customerA, $this->productA, 100);
        $this->adminSale($this->customerA, $this->productA, 500, 'admin');
        $this->adminSale($this->customerA, $this->productA, 700, 'ecommerce');
        $this->adminSale($this->customerA, $this->productA, 900, 'storefront');

        // Unfiltered, all four channels are in the revenue definition (#355).
        $this->assertSame(2200.0, $this->revenue($this->rows('by-customer', 'customers')));

        $this->assertSame(
            100.0,
            $this->revenue($this->rows('by-customer', 'customers', ['channel' => 'pos'])),
            'channel=pos keeps only POS revenue'
        );
        $this->assertSame(
            500.0,
            $this->revenue($this->rows('by-customer', 'customers', ['channel' => 'admin'])),
            'channel=admin keeps only admin-channel sales-order revenue'
        );
        $this->assertSame(
            700.0,
            $this->revenue($this->rows('by-customer', 'customers', ['channel' => 'ecommerce'])),
            'channel=ecommerce keeps only ecommerce-channel sales-order revenue'
        );
        $this->assertSame(
            900.0,
            $this->revenue($this->rows('by-customer', 'customers', ['channel' => 'storefront'])),
            'channel=storefront keeps only storefront-channel sales-order revenue (FR-ORD-03)'
        );
    }

    public function test_legal_entity_filter_is_applied_server_side(): void
    {
        [$entityA, $entityB] = $this->twoEntities();

        $customerInA = Customer::factory()->create([
            'name' => '593 Entity Customer A',
            'legal_entity_id' => $entityA,
        ]);
        $customerInB = Customer::factory()->create([
            'name' => '593 Entity Customer B',
            'legal_entity_id' => $entityB,
        ]);

        $this->adminSale($customerInA, $this->productA, 100, 'admin', $entityA);
        $this->adminSale($customerInB, $this->productB, 500, 'admin', $entityB);

        $rows = $this->rows('by-customer', 'customers', ['legal_entity_id' => $entityA]);

        $this->assertCount(1, $rows, 'legal_entity_id must scope the report to one entity');
        $this->assertSame($customerInA->id, $rows->first()['id']);
        $this->assertSame(100.0, $this->revenue($rows));
    }

    /** ResolveEntityContext's entity context (X-Entity-Id) scopes the report too. */
    public function test_entity_context_header_scopes_the_report(): void
    {
        [$entityA, $entityB] = $this->twoEntities();

        DB::table('user_entity_access')->insert([
            'user_id' => $this->admin->id,
            'legal_entity_id' => $entityA,
            'role' => 'viewer',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerInA = Customer::factory()->create(['name' => '593 Ctx Customer A', 'legal_entity_id' => $entityA]);
        $customerInB = Customer::factory()->create(['name' => '593 Ctx Customer B', 'legal_entity_id' => $entityB]);
        $this->adminSale($customerInA, $this->productA, 100, 'admin', $entityA);
        $this->adminSale($customerInB, $this->productB, 500, 'admin', $entityB);

        $rows = $this->rows('by-customer', 'customers', [], ['X-Entity-Id' => (string) $entityA]);

        $this->assertCount(1, $rows, 'the request entity context must scope the report');
        $this->assertSame($customerInA->id, $rows->first()['id']);
        $this->assertSame(100.0, $this->revenue($rows));
    }

    // ── Unknown filters are rejected, not ignored ─────────────────────────

    public function test_unknown_filters_are_rejected_with_422(): void
    {
        $this->adminSale($this->customerA, $this->productA, 100);

        $unknown = $this->analyticsRequest('by-customer', ['customer_id' => $this->customerA->id, 'nonsense' => '1']);
        $unknown->assertStatus(422);
        $this->assertStringContainsString(
            'nonsense',
            json_encode($unknown->json('errors') ?? $unknown->json()),
            'the 422 must name the rejected parameter'
        );

        // A parameter unknown to ONE endpoint must not be waved through
        // because another endpoint accepts it.
        $this->analyticsRequest('by-product', ['top' => 5, 'salesperson_id' => $this->salespersonA->id])->assertOk();
        $this->analyticsRequest('by-customer', ['top' => 5])->assertStatus(422);
    }

    public function test_an_invalid_channel_value_is_rejected_with_422(): void
    {
        $this->analyticsRequest('by-customer', ['channel' => 'telephone'])->assertStatus(422);
    }

    // ── The tag filter says out loud that POS is excluded ─────────────────

    public function test_tag_filter_surfaces_the_pos_exclusion(): void
    {
        $this->posSale($this->customerA, $this->productA, 100);
        $taggedOrder = $this->adminSale($this->customerA, $this->productA, 500, 'admin');

        $tags = app(TagService::class)->attach($taggedOrder, ['VIP 593']);
        $slug = $tags[0]->slug;

        $untagged = $this->analyticsRequest('by-customer');
        $untagged->assertOk();
        $this->assertSame(600.0, $this->revenue(collect($untagged->json('data.customers') ?? [])));
        $this->assertFalse(
            (bool) $untagged->json('data.pos_excluded_by_tag'),
            'without a tag filter POS revenue is included and the report must not claim otherwise'
        );

        $tagged = $this->analyticsRequest('by-customer', ['tag' => $slug]);
        $tagged->assertOk();
        $this->assertSame(
            500.0,
            $this->revenue(collect($tagged->json('data.customers') ?? [])),
            'the tag filter excludes POS (POS orders cannot be tagged)'
        );
        $this->assertTrue(
            (bool) $tagged->json('data.pos_excluded_by_tag'),
            'the report must surface that POS revenue was excluded by the tag filter'
        );
    }
}
