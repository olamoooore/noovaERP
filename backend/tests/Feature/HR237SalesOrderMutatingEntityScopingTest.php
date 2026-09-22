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
 * Issue #237 (CRITICAL) — #140's entity scoping covered only index()/show();
 * every mutating SalesOrder endpoint resolved the record via a bare
 * SalesOrder::find($id), so an entity-scoped user could confirm/allocate/
 * fulfil/invoice/cancel/reset/inspect/delete another entity's order by id.
 */
class HR237SalesOrderMutatingEntityScopingTest extends TenantTestCase
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
        // Mutating routes carry a sales write-permission gate; Admin passes the
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
    // Issue #237: mutating endpoints must resolve through the entity scope
    // ─────────────────────────────────────────────────────────────────────

    public function test_scoped_user_cannot_confirm_another_entity_order(): void
    {
        $orderB = $this->createOrderFor($this->customerB);
        $user = $this->userScopedTo([$this->entityA]);

        $this->actingAs($user)
            ->postJson("/api/v1/sales/orders/{$orderB->id}/confirm")
            ->assertStatus(404);

        $this->assertSame('draft', $orderB->fresh()->status,
            'Cross-entity confirm must not mutate the order');
    }

    public function test_scoped_user_cannot_update_another_entity_order(): void
    {
        $orderB = $this->createOrderFor($this->customerB);
        $user = $this->userScopedTo([$this->entityA]);

        $this->actingAs($user)
            ->putJson("/api/v1/sales/orders/{$orderB->id}", ['notes' => 'hijacked'])
            ->assertStatus(404);

        $this->assertNotSame('hijacked', $orderB->fresh()->notes);
    }

    public function test_scoped_user_cannot_allocate_another_entity_order(): void
    {
        $orderB = $this->createOrderFor($this->customerB);
        $user = $this->userScopedTo([$this->entityA]);

        $this->actingAs($user)
            ->postJson("/api/v1/sales/orders/{$orderB->id}/allocate")
            ->assertStatus(404);
    }

    public function test_scoped_user_cannot_cancel_another_entity_order(): void
    {
        $orderB = $this->createOrderFor($this->customerB);
        $user = $this->userScopedTo([$this->entityA]);

        $this->actingAs($user)
            ->postJson("/api/v1/sales/orders/{$orderB->id}/cancel", ['reason' => 'hijack'])
            ->assertStatus(404);

        $this->assertSame('draft', $orderB->fresh()->status);
    }

    public function test_scoped_user_cannot_delete_another_entity_order(): void
    {
        $orderB = $this->createOrderFor($this->customerB);
        $user = $this->userScopedTo([$this->entityA]);

        $this->actingAs($user)
            ->deleteJson("/api/v1/sales/orders/{$orderB->id}")
            ->assertStatus(404);

        $this->assertNotNull($orderB->fresh(), 'Cross-entity delete must not remove the order');
    }

    public function test_scoped_user_cannot_read_other_entity_order_links(): void
    {
        $orderB = $this->createOrderFor($this->customerB);
        $user = $this->userScopedTo([$this->entityA]);

        $this->actingAs($user)->getJson("/api/v1/sales/orders/{$orderB->id}/history")->assertStatus(404);
        $this->actingAs($user)->getJson("/api/v1/sales/orders/{$orderB->id}/invoice-links")->assertStatus(404);
        $this->actingAs($user)->getJson("/api/v1/sales/orders/{$orderB->id}/channel-references")->assertStatus(404);
        $this->actingAs($user)->getJson("/api/v1/sales/orders/{$orderB->id}/credit-preview")->assertStatus(404);
    }

    public function test_scoped_user_can_confirm_their_own_entity_order(): void
    {
        $orderA = $this->createOrderFor($this->customerA);
        $user = $this->userScopedTo([$this->entityA]);

        $this->actingAs($user)
            ->postJson("/api/v1/sales/orders/{$orderA->id}/confirm")
            ->assertStatus(200);

        $this->assertSame('confirmed', $orderA->fresh()->status);
    }

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
