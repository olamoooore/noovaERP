<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Validates the RequirePermission wiring fix for backend/modules/Sales/routes/api.php
 * (docs/module-gap-review-2026-08-24/MODULE-FRAMEWORK-GAP-ANALYSIS-2026-08-24.md, gap S1).
 *
 * Before the fix, Sales/routes/api.php only checked auth + tenant entitlement — any
 * authenticated user entitled to the 'sales' feature could mutate any sales order
 * regardless of role. These tests exercise the real router/middleware/controller
 * chain via postJson/getJson (not source-string assertions) to prove the fix holds.
 */
class SalesOrderPermissionEnforcementTest extends TenantTestCase
{
    protected User $viewer;

    protected User $salesRep;

    protected Customer $customer;

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
                'name' => 'Sales RBAC Test Tenant',
                'email' => fake()->unique()->safeEmail(),
                'plan' => 'standard',
            ]);
        });

        // Viewer role is granted sales.view but NOT sales.write (RolePermissionSeeder::PERMISSION_MATRIX).
        $this->viewer = User::factory()->create();
        $this->viewer->assignRole('Viewer');

        // Sales role is granted both sales.view and sales.write.
        $this->salesRep = User::factory()->create();
        $this->salesRep->assignRole('Sales');

        foreach ([
            ['user' => $this->viewer, 'role' => 'viewer'],
            ['user' => $this->salesRep, 'role' => 'sales'],
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
    }

    public function test_viewer_cannot_create_sales_order(): void
    {
        $response = $this->actingAs($this->viewer)->postJson('/api/v1/sales/orders', $this->validOrderPayload());

        $response->assertStatus(403);
        $response->assertJsonPath('required_permission', 'sales.write');
    }

    public function test_viewer_cannot_confirm_sales_order(): void
    {
        $order = $this->createOrderAs($this->salesRep);

        $response = $this->actingAs($this->viewer)->postJson("/api/v1/sales/orders/{$order->id}/confirm");

        $response->assertStatus(403);
        $response->assertJsonPath('required_permission', 'sales.write');
    }

    public function test_viewer_can_list_and_view_sales_orders(): void
    {
        $order = $this->createOrderAs($this->salesRep);

        $this->actingAs($this->viewer)->getJson('/api/v1/sales/orders')->assertStatus(200);
        $this->actingAs($this->viewer)->getJson("/api/v1/sales/orders/{$order->id}")->assertStatus(200);
    }

    public function test_sales_rep_can_create_sales_order(): void
    {
        $response = $this->actingAs($this->salesRep)->postJson('/api/v1/sales/orders', $this->validOrderPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('customer_id', $this->customer->id);
    }

    public function test_unauthenticated_request_is_rejected_before_permission_check(): void
    {
        $response = $this->postJson('/api/v1/sales/orders', $this->validOrderPayload());

        $response->assertStatus(401);
    }

    private function createOrderAs(User $user): \Modules\Sales\Models\SalesOrder
    {
        $response = $this->actingAs($user)->postJson('/api/v1/sales/orders', $this->validOrderPayload());
        $response->assertStatus(201);

        return \Modules\Sales\Models\SalesOrder::find($response->json('id'));
    }

    private function validOrderPayload(): array
    {
        return [
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'lines' => [
                [
                    'description' => 'Test line item',
                    'quantity' => 1,
                    'unit_price' => 100,
                ],
            ],
        ];
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
