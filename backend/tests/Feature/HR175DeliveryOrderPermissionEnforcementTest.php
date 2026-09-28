<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issues #175 / #145 (HIGH) — Delivery Order routes carried no RequirePermission
 * middleware, so any authenticated user with the tenant 'sales' entitlement
 * could create/complete/reverse/bulk-delete delivery orders regardless of role.
 *
 * These tests exercise the real router/middleware/controller chain via
 * postJson/getJson (not source-string assertions), mirroring
 * SalesOrderPermissionEnforcementTest.
 */
class HR175DeliveryOrderPermissionEnforcementTest extends TenantTestCase
{
    protected User $viewer;

    protected User $salesRep;

    protected User $accountant;

    protected Customer $customer;

    protected Warehouse $warehouse;

    protected Product $product;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate(true);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Viewer', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Sales', 'guard_name' => 'web']);

        Tenant::withoutEvents(function () {
            return Tenant::create([
                'id' => $this->tenantId,
                'name' => 'Delivery RBAC Test Tenant',
                'email' => fake()->unique()->safeEmail(),
                'plan' => 'standard',
            ]);
        });

        // Viewer has sales.view but NOT sales.write (RolePermissionSeeder::PERMISSION_MATRIX).
        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('Viewer');

        // Sales has both sales.view and sales.write.
        $this->salesRep = User::factory()->create();
        $this->salesRep->assignRole('Sales');

        // Accountant holds accounting.post (the document-release capability).
        $this->accountant = User::factory()->create();
        $this->accountant->assignRole(UserRole::ACCOUNTANT->spatieName());

        foreach ([
            ['user' => $this->viewer, 'role' => 'viewer'],
            ['user' => $this->salesRep, 'role' => 'sales'],
            ['user' => $this->accountant, 'role' => 'accountant'],
        ] as $entry) {
            DB::table('user_company_access')->insert([
                'user_id' => $entry['user']->id,
                'tenant_id' => $this->tenantId,
                'role' => $entry['role'],
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::create([
            'name' => 'RBAC Warehouse',
            'code' => 'RBAC-'.uniqid(),
            'is_active' => true,
        ]);
        $this->product = Product::factory()->create([
            'name' => 'RBAC Widget',
            'sku' => 'RBAC-'.uniqid(),
            'track_inventory' => false,
            'price' => 10,
            'cost' => 5,
        ]);
    }

    private function payload(): array
    {
        return [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => 'RBAC line',
                'quantity_delivered' => 1,
                'unit_price' => 10,
            ]],
        ];
    }

    private function createDeliveryOrderAs(User $user): int
    {
        $response = $this->actingAs($user)->postJson('/api/v1/sales/delivery-orders', $this->payload());
        $response->assertStatus(201, $response->getContent());

        return (int) $response->json('data.id');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Write actions denied for viewers
    // ─────────────────────────────────────────────────────────────────────

    public function test_viewer_cannot_create_delivery_order(): void
    {
        $response = $this->actingAs($this->viewer)->postJson('/api/v1/sales/delivery-orders', $this->payload());

        $response->assertStatus(403);
        $response->assertJsonPath('required_permission', 'sales.write');
    }

    public function test_viewer_cannot_complete_delivery_order(): void
    {
        $id = $this->createDeliveryOrderAs($this->salesRep);

        $response = $this->actingAs($this->viewer)->postJson("/api/v1/sales/delivery-orders/{$id}/complete");

        $response->assertStatus(403);
        $response->assertJsonPath('required_permission', 'sales.write');
    }

    public function test_viewer_cannot_confirm_delivery_order(): void
    {
        $id = $this->createDeliveryOrderAs($this->salesRep);

        $this->actingAs($this->viewer)->postJson("/api/v1/sales/delivery-orders/{$id}/confirm")
            ->assertStatus(403);
    }

    public function test_viewer_cannot_reverse_cancel_or_reset_delivery_order(): void
    {
        $id = $this->createDeliveryOrderAs($this->salesRep);

        foreach (['reverse', 'cancel', 'reset-to-draft'] as $action) {
            $this->actingAs($this->viewer)->postJson("/api/v1/sales/delivery-orders/{$id}/{$action}")
                ->assertStatus(403, "Viewer must be denied {$action}");
        }
    }

    public function test_viewer_cannot_bulk_delete_or_import_delivery_orders(): void
    {
        $this->actingAs($this->viewer)->postJson('/api/v1/sales/delivery-orders/bulk-delete', ['ids' => [1]])
            ->assertStatus(403);

        $this->actingAs($this->viewer)->postJson('/api/v1/sales/delivery-orders/import')
            ->assertStatus(403);
    }

    public function test_viewer_cannot_update_or_delete_delivery_order(): void
    {
        $id = $this->createDeliveryOrderAs($this->salesRep);

        $this->actingAs($this->viewer)->putJson("/api/v1/sales/delivery-orders/{$id}", $this->payload())
            ->assertStatus(403);

        $this->actingAs($this->viewer)->deleteJson("/api/v1/sales/delivery-orders/{$id}")
            ->assertStatus(403);
    }

    public function test_viewer_cannot_export_or_fetch_template(): void
    {
        // Reads, but gated behind sales.view (viewer HAS it) — proves the
        // read/write split did not accidentally over-restrict reads.
        $this->actingAs($this->viewer)->getJson('/api/v1/sales/delivery-orders/export?columns[]=delivery_order_number')->assertStatus(200);
        $this->actingAs($this->viewer)->getJson('/api/v1/sales/delivery-orders/template')->assertStatus(200);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Legitimate access preserved
    // ─────────────────────────────────────────────────────────────────────

    public function test_viewer_can_list_and_view_delivery_orders(): void
    {
        $id = $this->createDeliveryOrderAs($this->salesRep);

        $this->actingAs($this->viewer)->getJson('/api/v1/sales/delivery-orders')->assertStatus(200);
        $this->actingAs($this->viewer)->getJson("/api/v1/sales/delivery-orders/{$id}")->assertStatus(200);
    }

    public function test_only_accountant_can_confirm_a_delivery_order(): void
    {
        $id = $this->createDeliveryOrderAs($this->salesRep);

        // Sales prepares the document but cannot release it (Segregation of Duties).
        $this->actingAs($this->salesRep)->postJson("/api/v1/sales/delivery-orders/{$id}/confirm")
            ->assertStatus(403);
        $this->assertSame('draft', \Modules\Sales\Models\DeliveryOrder::findOrFail($id)->status);

        // Accountant holds accounting.post and can confirm.
        $this->actingAs($this->accountant)->postJson("/api/v1/sales/delivery-orders/{$id}/confirm")
            ->assertStatus(200);

        // Completing stays a warehouse/sales transition once confirmed.
        $this->actingAs($this->salesRep)->postJson("/api/v1/sales/delivery-orders/{$id}/complete")
            ->assertStatus(200);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/v1/sales/delivery-orders', $this->payload())->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers (mirrors SalesOrderPermissionEnforcementTest)
    // ─────────────────────────────────────────────────────────────────────

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
