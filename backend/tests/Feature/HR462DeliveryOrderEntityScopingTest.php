<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Services\DeliveryOrderService;
use Modules\Sales\Services\SalesOrderService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #462 (DO10) — DeliveryOrderController loaded every delivery order via
 * a bare DeliveryOrder::find($id) with no entity scope, and the list query had
 * none either. A user restricted to legal entity A could view, complete,
 * reverse or invoice entity B's delivery orders. Mirrors the #140/#237 scoping
 * already applied to sales orders (EntityAccessService::accessibleEntityIds).
 */
class HR462DeliveryOrderEntityScopingTest extends TenantTestCase
{
    private int $entityA;

    private int $entityB;

    private Customer $customerA;

    private Customer $customerB;

    private Product $product;

    private Warehouse $warehouse;

    private User $admin;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Viewer', 'guard_name' => 'web']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Delivery Order Entity Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Delivery Order Entity Group',
            'code' => 'DEG-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity A', 'code' => 'DEA-'.Str::upper(Str::random(5)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity B', 'code' => 'DEB-'.Str::upper(Str::random(5)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->customerA = Customer::factory()->create(['legal_entity_id' => $this->entityA, 'is_active' => true]);
        $this->customerB = Customer::factory()->create(['legal_entity_id' => $this->entityB, 'is_active' => true]);

        // Fixture creator: an unscoped admin (no user_entity_access rows), so
        // creating entity B fixtures through the real route is not blocked.
        $this->admin = User::factory()->create();
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

        // Issue #176: DO completion may post COGS, which needs an open period.
        \App\Models\FiscalPeriod::firstOrCreate(
            ['code' => now()->format('Y-m')],
            [
                'name' => now()->format('F Y'),
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => (int) now()->format('Y'),
                'is_locked' => false,
                'status' => 'open',
                'created_by' => $this->admin->id,
            ]
        );

        $this->warehouse = Warehouse::create([
            'name' => 'Delivery Order Entity Warehouse',
            'code' => 'DEW-'.uniqid(),
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Delivery Order Entity Widget',
            'sku' => 'DEW-'.uniqid(),
            'track_inventory' => false,
            'price' => 10,
            'cost' => 5,
        ]);
    }

    private function createDeliveryOrderFor(Customer $customer): DeliveryOrder
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/sales/delivery-orders', [
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => 'Entity scoping line',
                'quantity_delivered' => 1,
                'unit_price' => 10,
            ]],
        ]);
        $response->assertStatus(201, $response->getContent());

        return DeliveryOrder::findOrFail((int) $response->json('data.id'));
    }

    private function createConfirmedSalesOrderFor(Customer $customer): \Modules\Sales\Models\SalesOrder
    {
        $service = app(SalesOrderService::class);

        $order = $service->createOrder([
            'customer_id' => $customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->admin->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Entity scoping line',
                'quantity' => 5,
                'unit_price' => 10,
                'tax_rate' => 0,
            ]],
        ]);

        return $service->confirmOrder($order);
    }

    private function userScopedTo(array $entityIds): User
    {
        $user = User::factory()->create();
        // Mutating routes carry a sales write/accounting gate; Admin passes the
        // middleware while the entity grant still confines data access.
        $user->assignRole('Admin');

        foreach ($entityIds as $index => $entityId) {
            DB::table('user_entity_access')->insert([
                'user_id' => $user->id,
                'legal_entity_id' => $entityId,
                'role' => 'viewer',
                'is_default' => $index === 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Entity stamping (inherit from the sales order or the customer)
    // ─────────────────────────────────────────────────────────────────────

    public function test_standalone_delivery_order_inherits_the_customers_legal_entity(): void
    {
        $orderA = $this->createDeliveryOrderFor($this->customerA);

        $this->assertSame(
            $this->entityA,
            (int) $orderA->fresh()->legal_entity_id,
            'A standalone DO must inherit the customer\'s legal entity'
        );
    }

    public function test_linked_delivery_order_inherits_the_sales_orders_legal_entity(): void
    {
        $salesOrder = $this->createConfirmedSalesOrderFor($this->customerA);
        $line = $salesOrder->lines()->firstOrFail();

        $deliveryOrder = app(DeliveryOrderService::class)->createDeliveryOrder($salesOrder, [
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->admin->id,
            'lines' => [[
                'sales_order_line_id' => $line->id,
                'quantity_delivered' => 1,
            ]],
        ]);

        $this->assertSame(
            $this->entityA,
            (int) $deliveryOrder->fresh()->legal_entity_id,
            'A sales-order-linked DO must inherit the order\'s legal entity'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Query scoping
    // ─────────────────────────────────────────────────────────────────────

    public function test_scoped_user_only_sees_their_entities_delivery_orders(): void
    {
        $orderA = $this->createDeliveryOrderFor($this->customerA);
        $orderB = $this->createDeliveryOrderFor($this->customerB);

        $user = $this->userScopedTo([$this->entityA]);

        $list = $this->actingAs($user)->getJson('/api/v1/sales/delivery-orders');
        $list->assertStatus(200, $list->getContent());

        $numbers = collect($list->json('data'))->pluck('delivery_order_number');
        $this->assertTrue($numbers->contains($orderA->delivery_order_number));
        $this->assertFalse($numbers->contains($orderB->delivery_order_number), 'Entity B delivery order must not leak into the list');
    }

    public function test_scoped_user_cannot_view_another_entities_delivery_order(): void
    {
        $orderA = $this->createDeliveryOrderFor($this->customerA);
        $orderB = $this->createDeliveryOrderFor($this->customerB);

        $user = $this->userScopedTo([$this->entityA]);

        $this->actingAs($user)->getJson("/api/v1/sales/delivery-orders/{$orderA->id}")->assertStatus(200);
        $this->actingAs($user)->getJson("/api/v1/sales/delivery-orders/{$orderB->id}")->assertStatus(404);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Mutating endpoints must resolve through the entity scope
    // ─────────────────────────────────────────────────────────────────────

    public function test_scoped_user_cannot_update_another_entities_delivery_order(): void
    {
        $orderB = $this->createDeliveryOrderFor($this->customerB);
        $user = $this->userScopedTo([$this->entityA]);

        $this->actingAs($user)
            ->putJson("/api/v1/sales/delivery-orders/{$orderB->id}", ['notes' => 'hijacked'])
            ->assertStatus(404);

        $this->assertNotSame('hijacked', $orderB->fresh()->notes);
    }

    public function test_scoped_user_cannot_complete_another_entities_delivery_order(): void
    {
        $orderB = $this->createDeliveryOrderFor($this->customerB);
        $user = $this->userScopedTo([$this->entityA]);

        $this->actingAs($user)
            ->postJson("/api/v1/sales/delivery-orders/{$orderB->id}/complete")
            ->assertStatus(404);

        $this->assertSame('draft', $orderB->fresh()->status, 'Cross-entity complete must not mutate the order');
    }

    public function test_scoped_user_cannot_reverse_another_entities_delivery_order(): void
    {
        $orderB = $this->createDeliveryOrderFor($this->customerB);
        $user = $this->userScopedTo([$this->entityA]);

        $this->actingAs($user)
            ->postJson("/api/v1/sales/delivery-orders/{$orderB->id}/reverse", ['reason' => 'hijack'])
            ->assertStatus(404);
    }

    public function test_scoped_user_cannot_cancel_another_entities_delivery_order(): void
    {
        $orderB = $this->createDeliveryOrderFor($this->customerB);
        $user = $this->userScopedTo([$this->entityA]);

        $this->actingAs($user)
            ->postJson("/api/v1/sales/delivery-orders/{$orderB->id}/cancel", ['reason' => 'hijack'])
            ->assertStatus(404);

        $this->assertSame('draft', $orderB->fresh()->status, 'Cross-entity cancel must not mutate the order');
    }

    public function test_scoped_user_cannot_invoice_another_entities_delivery_order(): void
    {
        $orderB = $this->createDeliveryOrderFor($this->customerB);
        $user = $this->userScopedTo([$this->entityA]);

        $this->actingAs($user)
            ->postJson("/api/v1/sales/delivery-orders/{$orderB->id}/create-invoice")
            ->assertStatus(404);

        $this->assertNull($orderB->fresh()->invoice_id, 'Cross-entity invoice must not link an invoice');
    }

    public function test_scoped_user_cannot_delete_another_entities_delivery_order(): void
    {
        $orderB = $this->createDeliveryOrderFor($this->customerB);
        $user = $this->userScopedTo([$this->entityA]);

        $this->actingAs($user)
            ->deleteJson("/api/v1/sales/delivery-orders/{$orderB->id}")
            ->assertStatus(404);

        $this->assertNotNull($orderB->fresh(), 'Cross-entity delete must not remove the order');
    }

    public function test_scoped_user_can_read_and_update_their_own_entities_delivery_order(): void
    {
        $orderA = $this->createDeliveryOrderFor($this->customerA);
        $user = $this->userScopedTo([$this->entityA]);

        $this->actingAs($user)->getJson("/api/v1/sales/delivery-orders/{$orderA->id}")->assertStatus(200);

        $this->actingAs($user)
            ->putJson("/api/v1/sales/delivery-orders/{$orderA->id}", ['notes' => 'mine'])
            ->assertStatus(200);

        $this->assertSame('mine', $orderA->fresh()->notes);
    }

    public function test_scoped_user_cannot_read_another_entities_sales_order_deliveries(): void
    {
        $salesOrderB = $this->createConfirmedSalesOrderFor($this->customerB);
        $user = $this->userScopedTo([$this->entityA]);

        $this->actingAs($user)
            ->getJson("/api/v1/sales/orders/{$salesOrderB->id}/deliveries")
            ->assertStatus(404);
    }

    public function test_user_without_entity_grants_keeps_the_fail_open_default(): void
    {
        $orderA = $this->createDeliveryOrderFor($this->customerA);
        $orderB = $this->createDeliveryOrderFor($this->customerB);

        $user = User::factory()->create();
        $user->assignRole('Viewer');
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'viewer',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $list = $this->actingAs($user)->getJson('/api/v1/sales/delivery-orders');
        $list->assertStatus(200);

        $numbers = collect($list->json('data'))->pluck('delivery_order_number');
        $this->assertTrue($numbers->contains($orderA->delivery_order_number));
        $this->assertTrue($numbers->contains($orderB->delivery_order_number));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers (mirrors HR140/HR237)
    // ─────────────────────────────────────────────────────────────────────

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
