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
use Modules\Sales\Services\SalesOrderService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #140 (HIGH) — sales_orders / sales_order_lines had no entity column, so
 * entity-access gating could not scope the data. This covers the schema
 * addition, creation stamping, backfill, and query scoping.
 */
class HR140SalesOrderEntityScopingTest extends TenantTestCase
{
    private int $entityA;

    private int $entityB;

    private Customer $customerA;

    private Customer $customerB;

    private Product $product;

    private Warehouse $warehouse;

    private User $orderCreator;

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
            'name' => 'Sales Entity Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Sales Entity Group',
            'code' => 'SEG-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity A', 'code' => 'SEA-'.Str::upper(Str::random(5)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity B', 'code' => 'SEB-'.Str::upper(Str::random(5)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->customerA = Customer::factory()->create(['legal_entity_id' => $this->entityA, 'is_active' => true]);
        $this->customerB = Customer::factory()->create(['legal_entity_id' => $this->entityB, 'is_active' => true]);

        // Create (and authenticate as) the tenant user BEFORE any Product —
        // ProductAuditLog requires a tenant user for audit attribution.
        $this->orderCreator = User::factory()->create();
        $this->orderCreator->assignRole('Viewer');
        $this->actingAs($this->orderCreator);

        $this->warehouse = Warehouse::create([
            'name' => 'Sales Entity Warehouse',
            'code' => 'SEW-'.uniqid(),
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Sales Entity Widget',
            'sku' => 'SEW-'.uniqid(),
            'track_inventory' => false,
            'price' => 10,
            'cost' => 5,
        ]);
    }

    private function createOrderFor(Customer $customer): \Modules\Sales\Models\SalesOrder
    {
        return app(SalesOrderService::class)->createOrder([
            'customer_id' => $customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->orderCreator->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Entity scoping line',
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 0,
            ]],
        ]);
    }

    private function userScopedTo(array $entityIds): User
    {
        $user = User::factory()->create();
        $user->assignRole('Viewer');

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
            'role' => 'viewer',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Creation stamping
    // ─────────────────────────────────────────────────────────────────────

    public function test_created_order_and_lines_are_stamped_with_the_customers_entity(): void
    {
        $order = $this->createOrderFor($this->customerA);

        $this->assertSame($this->entityA, (int) $order->fresh()->legal_entity_id);
        $this->assertSame($this->entityA, (int) $order->lines()->first()->legal_entity_id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Query scoping
    // ─────────────────────────────────────────────────────────────────────

    public function test_single_entity_user_only_sees_their_entity_orders(): void
    {
        $orderA = $this->createOrderFor($this->customerA);
        $orderB = $this->createOrderFor($this->customerB);

        $user = $this->userScopedTo([$this->entityA]);

        $list = $this->actingAs($user)->getJson('/api/v1/sales/orders');
        $list->assertStatus(200, $list->getContent());

        $numbers = collect($list->json('data'))->pluck('sales_order_number');
        $this->assertTrue($numbers->contains($orderA->sales_order_number));
        $this->assertFalse($numbers->contains($orderB->sales_order_number), 'Entity B order must not leak');

        $this->actingAs($user)->getJson("/api/v1/sales/orders/{$orderA->id}")->assertStatus(200);
        $this->actingAs($user)->getJson("/api/v1/sales/orders/{$orderB->id}")->assertStatus(404);
    }

    public function test_multi_entity_user_sees_the_orders_of_their_entities(): void
    {
        $orderA = $this->createOrderFor($this->customerA);
        $orderB = $this->createOrderFor($this->customerB);

        $user = $this->userScopedTo([$this->entityA, $this->entityB]);

        $list = $this->actingAs($user)->getJson('/api/v1/sales/orders');
        $list->assertStatus(200);

        $numbers = collect($list->json('data'))->pluck('sales_order_number');
        $this->assertTrue($numbers->contains($orderA->sales_order_number));
        $this->assertTrue($numbers->contains($orderB->sales_order_number));
    }

    public function test_user_without_entity_grants_keeps_the_fail_open_default(): void
    {
        $orderA = $this->createOrderFor($this->customerA);
        $orderB = $this->createOrderFor($this->customerB);

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

        $list = $this->actingAs($user)->getJson('/api/v1/sales/orders');
        $list->assertStatus(200);

        $numbers = collect($list->json('data'))->pluck('sales_order_number');
        $this->assertTrue($numbers->contains($orderA->sales_order_number));
        $this->assertTrue($numbers->contains($orderB->sales_order_number));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Backfill migration
    // ─────────────────────────────────────────────────────────────────────

    public function test_backfill_migration_assigns_null_entity_orders_from_the_customer(): void
    {
        // Simulate a legacy row that predates the column backfill.
        $legacyId = DB::table('sales_orders')->insertGetId([
            'sales_order_number' => 'SO-LEGACY-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customerA->id,
            'legal_entity_id' => null,
            'channel' => 'admin',
            'status' => 'draft',
            'accounting_status' => 'not_posted',
            'order_date' => now()->toDateString(),
            'subtotal' => 10,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 10,
            'created_by' => $this->orderCreator->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(DB::table('sales_orders')->where('id', $legacyId)->value('legal_entity_id'));

        $migration = require base_path('database/migrations/tenant/2026_09_21_000008_add_legal_entity_to_sales_orders.php');
        $migration->up();

        $this->assertSame(
            $this->entityA,
            (int) DB::table('sales_orders')->where('id', $legacyId)->value('legal_entity_id'),
            'Backfill must assign the customer\'s legal entity'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers (mirrors HR175)
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
