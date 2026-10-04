<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Supplier;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #408 (SALES-ODOO): commitment (promised) date, expected date computed
 * from product/supplier lead times, and a shipping policy ("as soon as
 * possible" partial deliveries vs "when all products are ready" full-only
 * delivery). The policy is enforced through the real HTTP DO creation route so
 * a partial delivery on a when-all-ready order is refused with 422.
 */
class Issue408SalesPromiseDateTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $productA;

    private Product $productB;

    private Warehouse $warehouse;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue 408 Promise Date Tenant',
            'email' => 'issue408-'.uniqid().'@test.local',
            'plan' => 'standard',
        ]));

        $this->admin = User::create([
            'name' => 'Issue 408 Admin',
            'email' => 'issue408-admin-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->admin->assignRole('Admin');

        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin);

        $this->customer = Customer::factory()->create(['name' => 'Issue 408 Customer']);

        $this->productA = Product::factory()->create([
            'name' => 'Issue 408 Widget A',
            'sku' => 'I408-A-'.uniqid(),
            'price' => 10,
            'cost' => 4,
            'track_inventory' => false,
        ]);
        $this->productB = Product::factory()->create([
            'name' => 'Issue 408 Widget B',
            'sku' => 'I408-B-'.uniqid(),
            'price' => 20,
            'cost' => 8,
            'track_inventory' => false,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Issue 408 Warehouse',
            'code' => 'I408-'.uniqid(),
            'is_active' => true,
        ]);
    }

    /**
     * The expected date is computed from the product's supplier lead time; the
     * pivot row's lead_time_days is the product-specific source and the
     * supplier's default is the fallback.
     */
    private function giveProductLeadTime(Product $product, int $days): void
    {
        $supplier = Supplier::create([
            'name' => 'Issue 408 Supplier '.uniqid(),
            'code' => 'I408-S-'.uniqid(),
            'default_lead_time_days' => $days,
        ]);

        ProductSupplier::create([
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'cost' => 1,
            'lead_time_days' => $days,
        ]);
    }

    private function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'order_date' => '2026-10-01',
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->productA->id,
                'description' => $this->productA->name,
                'quantity' => 10,
                'unit_price' => 10,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ], $overrides);
    }

    private function createConfirmedOrder(array $overrides = []): SalesOrder
    {
        $response = $this->postJson('/api/v1/sales/orders', $this->orderPayload($overrides));
        $response->assertStatus(201);

        $confirm = $this->postJson("/api/v1/sales/orders/{$response->json('id')}/confirm");
        $confirm->assertOk();

        return SalesOrder::findOrFail($response->json('id'));
    }

    // ════════════════════════════════════════════════════════════════════════
    //  Commitment date + computed expected date on orders
    // ════════════════════════════════════════════════════════════════════════

    public function test_sales_order_stores_commitment_date_and_computes_expected_date_from_lead_time(): void
    {
        $this->giveProductLeadTime($this->productA, 5);

        $response = $this->postJson('/api/v1/sales/orders', $this->orderPayload([
            'commitment_date' => '2026-10-20',
            'shipping_policy' => 'when_all_ready',
        ]));

        $response->assertStatus(201);
        $this->assertSame('when_all_ready', $response->json('shipping_policy'));
        $this->assertStringStartsWith('2026-10-20', (string) $response->json('commitment_date'));
        // Order date 2026-10-01 + 5 lead-time days.
        $this->assertSame('2026-10-06', $response->json('expected_date'));

        $order = SalesOrder::findOrFail($response->json('id'));
        $this->assertSame('2026-10-20', $order->commitment_date?->toDateString());
        $this->assertSame('when_all_ready', $order->shipping_policy);
    }

    public function test_commitment_date_and_shipping_policy_are_editable_on_a_draft_order(): void
    {
        $lead = $this->postJson('/api/v1/sales/orders', $this->orderPayload());
        $lead->assertStatus(201);
        $orderId = $lead->json('id');

        $update = $this->putJson("/api/v1/sales/orders/{$orderId}", [
            'commitment_date' => '2026-11-05',
            'shipping_policy' => 'when_all_ready',
        ]);

        $update->assertOk();
        $this->assertSame('when_all_ready', $update->json('shipping_policy'));
        $this->assertStringStartsWith('2026-11-05', (string) $update->json('commitment_date'));

        $order = SalesOrder::findOrFail($orderId);
        $this->assertSame('2026-11-05', $order->commitment_date?->toDateString());
        $this->assertSame('when_all_ready', $order->shipping_policy);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  Quotation fields carried to the sales order
    // ════════════════════════════════════════════════════════════════════════

    public function test_quotation_stores_promise_dates_and_carries_them_to_the_sales_order(): void
    {
        $this->giveProductLeadTime($this->productA, 5);

        $create = $this->postJson('/api/v1/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => '2026-10-01',
            'commitment_date' => '2026-10-20',
            'shipping_policy' => 'when_all_ready',
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->productA->id,
                'description' => $this->productA->name,
                'quantity' => 2,
                'unit_price' => 10,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        $create->assertStatus(201);
        $this->assertSame('when_all_ready', $create->json('data.shipping_policy'));
        $this->assertSame('2026-10-06', $create->json('data.expected_date'));

        $quotationId = $create->json('data.id');

        $confirm = $this->postJson("/api/v1/quotations/{$quotationId}/confirm");
        $confirm->assertOk();

        $salesOrderId = $confirm->json('data.sales_order_id');
        $this->assertNotNull($salesOrderId, 'Confirming the quotation must create its sales order.');

        $order = SalesOrder::findOrFail($salesOrderId);
        $this->assertSame('2026-10-20', $order->commitment_date?->toDateString());
        $this->assertSame('when_all_ready', $order->shipping_policy);
        // The order's own order date (set at conversion) plus the 5-day lead.
        $this->assertSame(
            $order->order_date->copy()->addDays(5)->toDateString(),
            $order->expected_date
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    //  Shipping policy enforcement on delivery-order creation
    // ════════════════════════════════════════════════════════════════════════

    private function deliveryLines(SalesOrder $order, array $quantities): array
    {
        $lines = [];
        foreach ($order->lines()->orderBy('id')->get() as $index => $line) {
            if (! array_key_exists($index, $quantities)) {
                continue;
            }

            $lines[] = [
                'sales_order_line_id' => $line->id,
                'quantity_delivered' => $quantities[$index],
            ];
        }

        return $lines;
    }

    public function test_when_all_ready_refuses_a_partial_delivery_order_with_422(): void
    {
        $order = $this->createConfirmedOrder([
            'commitment_date' => '2026-10-20',
            'shipping_policy' => 'when_all_ready',
            'lines' => [
                [
                    'line_type' => 'product',
                    'product_id' => $this->productA->id,
                    'description' => $this->productA->name,
                    'quantity' => 10,
                    'unit_price' => 10,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ],
                [
                    'line_type' => 'product',
                    'product_id' => $this->productB->id,
                    'description' => $this->productB->name,
                    'quantity' => 5,
                    'unit_price' => 20,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ],
            ],
        ]);

        // Only the first line, and only half of it: clearly partial.
        $partial = $this->postJson('/api/v1/sales/delivery-orders', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => $this->deliveryLines($order, [0 => 5]),
        ]);

        $partial->assertStatus(422);
        $this->assertStringContainsString('when all products are ready', mb_strtolower((string) $partial->json('message')));
        $this->assertSame(0, DeliveryOrder::where('sales_order_id', $order->id)->count());

        // First line complete but second line absent is still a partial delivery.
        $missingLine = $this->postJson('/api/v1/sales/delivery-orders', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => $this->deliveryLines($order, [0 => 10]),
        ]);

        $missingLine->assertStatus(422);
        $this->assertSame(0, DeliveryOrder::where('sales_order_id', $order->id)->count());

        // Covering every remaining line in full is accepted.
        $full = $this->postJson('/api/v1/sales/delivery-orders', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => $this->deliveryLines($order, [0 => 10, 1 => 5]),
        ]);

        $full->assertStatus(201);
        $this->assertSame(2, count($full->json('data.lines')));
        // The committed promise date is carried to the delivery order.
        $this->assertStringStartsWith('2026-10-20', (string) $full->json('data.expected_date'));
    }

    public function test_asap_policy_still_allows_partial_delivery_orders(): void
    {
        $order = $this->createConfirmedOrder([
            'commitment_date' => '2026-10-20',
            'shipping_policy' => 'asap',
        ]);

        $partial = $this->postJson('/api/v1/sales/delivery-orders', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => $this->deliveryLines($order, [0 => 4]),
        ]);

        $partial->assertStatus(201);
        $this->assertSame(1, DeliveryOrder::where('sales_order_id', $order->id)->count());
    }

    // ════════════════════════════════════════════════════════════════════════
    //  Late indicator exposed to the lists and the dashboard queue (#365)
    // ════════════════════════════════════════════════════════════════════════

    public function test_late_orders_are_flagged_and_filterable_on_the_list_endpoint(): void
    {
        $late = $this->createConfirmedOrder([
            'commitment_date' => now()->subDays(3)->toDateString(),
        ]);
        $onTime = $this->createConfirmedOrder([
            'commitment_date' => now()->addDays(3)->toDateString(),
        ]);

        $index = $this->getJson('/api/v1/sales/orders');
        $index->assertOk();

        $rows = collect($index->json('data'))->keyBy('id');
        $this->assertTrue((bool) $rows[$late->id]['is_late'], 'An undelivered order past its commitment date must be late.');
        $this->assertFalse((bool) $rows[$onTime->id]['is_late'], 'An order inside its commitment date must not be late.');

        $lateOnly = $this->getJson('/api/v1/sales/orders?late=1');
        $lateOnly->assertOk();
        $ids = collect($lateOnly->json('data'))->pluck('id')->all();
        $this->assertContains($late->id, $ids);
        $this->assertNotContains($onTime->id, $ids);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  Test scaffolding (mirrors SalesOrderPermissionEnforcementTest)
    // ════════════════════════════════════════════════════════════════════════

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(fn (string $tenantId, string $key) => [
                'key' => $key,
                'is_entitled' => $isEntitled,
                'source' => $isEntitled ? 'tier_included' : null,
                'name' => strtoupper($key),
            ]);
        $mock->shouldReceive('all')
            ->andReturn($isEntitled ? [['key' => 'sales', 'source' => 'tier_included', 'name' => 'SALES']] : []);
        $mock->shouldReceive('invalidate')->andReturnNull();

        app()->instance(FeatureGateService::class, $mock);
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }

        \App\Models\Feature::query()->updateOrCreate(
            ['key' => 'sales'],
            [
                'name' => 'Sales',
                'description' => 'Sales module feature gate for tests',
                'is_core' => false,
                'price' => 0,
            ]
        );
    }
}
