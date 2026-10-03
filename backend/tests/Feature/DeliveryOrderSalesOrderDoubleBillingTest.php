<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #455 (DO3): DO→invoice incremented only delivery_order_lines.quantity_invoiced
 * and SO→invoice only sales_order_lines.quantity_invoiced — neither saw the
 * other, so the same goods could be billed from both surfaces.
 *
 * Guard: either invoice path consumes the quantity on both sides, and the
 * other surface reports "nothing left to invoice".
 */
class DeliveryOrderSalesOrderDoubleBillingTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        Tenant::withoutEvents(function () {
            return Tenant::create([
                'id' => $this->tenantId,
                'name' => 'DO Double Billing Tenant',
                'email' => fake()->unique()->safeEmail(),
                'plan' => 'standard',
            ]);
        });

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Setting::setValue('sales.delivery_order.default_requires_approval', false);

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 1000000]);
        $this->warehouse = Warehouse::create([
            'name' => 'DB Warehouse',
            'code' => 'DB-'.uniqid(),
            'is_active' => true,
        ]);
        $this->product = Product::factory()->create([
            'name' => 'Double Billing Widget',
            'sku' => 'DBW-'.uniqid(),
            'track_inventory' => false,
            'price' => 100,
            'cost' => 40,
        ]);
    }

    private function confirmedOrder(float $quantity = 5): SalesOrder
    {
        $service = app(SalesOrderService::class);

        $order = $service->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->admin->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => $quantity,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        return $service->allocateOrder($service->confirmOrder($order));
    }

    private function deliveryOrderFor(SalesOrder $order, float $quantity = 5): DeliveryOrder
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/sales/delivery-orders', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'lines' => [[
                'sales_order_line_id' => $order->lines->first()->id,
                'quantity_delivered' => $quantity,
            ]],
        ]);
        $response->assertStatus(201, $response->getContent());

        return DeliveryOrder::with('lines')->find($response->json('data.id'));
    }

    public function test_invoice_from_a_delivery_order_stops_the_sales_order_from_billing_again(): void
    {
        $order = $this->confirmedOrder(5);
        $deliveryOrder = $this->deliveryOrderFor($order, 5);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/create-invoice")
            ->assertStatus(201);

        $soLine = $order->lines()->first()->fresh();
        $this->assertSame(5.0, (float) $soLine->quantity_invoiced, 'DO invoicing must consume the sales-order line quantity too.');

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/orders/{$order->id}/invoice");

        $response->assertStatus(422);
        $this->assertStringContainsString('no invoiceable quantities', strtolower($response->json('message')));
    }

    public function test_invoice_from_the_sales_order_stops_the_delivery_order_from_billing_again(): void
    {
        $order = $this->confirmedOrder(5);
        $deliveryOrder = $this->deliveryOrderFor($order, 5);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/orders/{$order->id}/invoice")
            ->assertStatus(201);

        $this->assertSame(
            5.0,
            (float) $deliveryOrder->lines()->first()->fresh()->quantity_invoiced,
            'SO invoicing must consume the linked delivery-order lines too.'
        );

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/create-invoice");

        $response->assertStatus(422);
        $this->assertSame('Nothing left to invoice on this delivery order', $response->json('message'));
    }

    public function test_partial_delivery_invoice_leaves_only_the_unbilled_remainder_to_the_order(): void
    {
        $order = $this->confirmedOrder(5);
        $deliveryOrder = $this->deliveryOrderFor($order, 2);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/create-invoice")
            ->assertStatus(201);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/orders/{$order->id}/invoice");

        $response->assertStatus(201, $response->getContent());

        $invoice = Invoice::with('lines')->find($response->json('invoice.id'));
        $this->assertSame(3.0, round((float) $invoice->lines->sum('quantity'), 4), 'Only the unbilled remainder (5 - 2) may be invoiced.');
        $this->assertSame(300.0, round((float) $invoice->total, 2));
        $this->assertSame(5.0, round((float) $order->lines()->first()->fresh()->quantity_invoiced, 4));
    }

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(function (string $tenantId, string $key) use ($isEntitled) {
                return [
                    'key' => $key,
                    'is_entitled' => $isEntitled,
                    'source' => $isEntitled ? 'tier_included' : null,
                    'name' => strtoupper($key),
                ];
            });

        $this->app->instance(FeatureGateService::class, $mock);
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
    }
}
