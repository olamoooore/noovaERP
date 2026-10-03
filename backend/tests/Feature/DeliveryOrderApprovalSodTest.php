<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #454 (DO2): the /approve and /reject-approval routes required only
 * sales.write and flipped the flag without any reviewer or segregation-of-
 * duties check — any sales user could approve (or reject) a delivery order,
 * including their own. The decision now runs through ApprovalService
 * (reviewer pool, pending-only, no self-approval).
 *
 * Router-level: postJson through middleware -> controller -> service.
 */
class DeliveryOrderApprovalSodTest extends TenantTestCase
{
    private User $submitter;

    private User $otherSales;

    private User $reviewer;

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
        foreach (['Sales', 'Manager', 'Admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Tenant::withoutEvents(function () {
            return Tenant::create([
                'id' => $this->tenantId,
                'name' => 'DO Approval SoD Tenant',
                'email' => fake()->unique()->safeEmail(),
                'plan' => 'standard',
            ]);
        });

        $this->submitter = User::factory()->create();
        $this->submitter->assignRole('Sales');

        $this->otherSales = User::factory()->create();
        $this->otherSales->assignRole('Sales');

        $this->reviewer = User::factory()->create();
        $this->reviewer->assignRole(UserRole::MANAGER->spatieName());

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN->spatieName());

        foreach ([
            ['user' => $this->submitter, 'role' => 'sales'],
            ['user' => $this->otherSales, 'role' => 'sales'],
            ['user' => $this->reviewer, 'role' => 'manager'],
            ['user' => $this->admin, 'role' => 'admin'],
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

        // Approval workflow: every delivery order needs approval; this
        // manager is the configured reviewer pool.
        Setting::setValue('sales.delivery_order.default_requires_approval', true);
        Setting::setValue('sales.delivery_order.reviewer_user_ids', [$this->reviewer->id]);
        Setting::setValue('sales.delivery_order.reviewer_roles', []);

        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::create([
            'name' => 'DO SoD Warehouse',
            'code' => 'DOS-'.uniqid(),
            'is_active' => true,
        ]);
        $this->product = Product::factory()->create([
            'name' => 'DO SoD Widget',
            'sku' => 'DOS-'.uniqid(),
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
                'description' => 'DO SoD line',
                'quantity_delivered' => 1,
                'unit_price' => 10,
            ]],
        ];
    }

    private function createPendingDeliveryOrderAs(User $user): DeliveryOrder
    {
        $response = $this->actingAs($user)->postJson('/api/v1/sales/delivery-orders', $this->payload());
        $response->assertStatus(201, $response->getContent());

        $deliveryOrder = DeliveryOrder::find($response->json('data.id'));
        $this->assertSame('pending', $deliveryOrder->approval_status);
        $this->assertTrue(
            ApprovalRequest::where('subject_type', DeliveryOrder::class)
                ->where('subject_id', $deliveryOrder->id)
                ->where('status', 'pending')
                ->exists(),
            'A pending approval request must exist for the pending delivery order.'
        );

        return $deliveryOrder;
    }

    public function test_non_reviewer_cannot_approve_a_pending_delivery_order(): void
    {
        $deliveryOrder = $this->createPendingDeliveryOrderAs($this->submitter);

        // Has sales.write on the route, but is not in the reviewer pool.
        $response = $this->actingAs($this->otherSales)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/approve");

        $response->assertStatus(403);
        $this->assertSame('pending', $deliveryOrder->fresh()->approval_status);
    }

    public function test_submitter_cannot_approve_their_own_delivery_order(): void
    {
        $deliveryOrder = $this->createPendingDeliveryOrderAs($this->submitter);

        $response = $this->actingAs($this->submitter)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/approve");

        $response->assertStatus(403);
        $this->assertStringContainsString('Self-approval is not permitted', $response->json('message'));
        $this->assertSame('pending', $deliveryOrder->fresh()->approval_status);
    }

    public function test_reviewer_can_approve_and_non_pending_approval_is_422(): void
    {
        $deliveryOrder = $this->createPendingDeliveryOrderAs($this->submitter);

        $response = $this->actingAs($this->reviewer)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/approve");

        $response->assertStatus(200, $response->getContent());
        $this->assertSame('approved', $deliveryOrder->fresh()->approval_status);
        $this->assertSame('approved', ApprovalRequest::where('subject_type', DeliveryOrder::class)
            ->where('subject_id', $deliveryOrder->id)->latest('id')->value('status'));

        $again = $this->actingAs($this->reviewer)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/approve");

        $again->assertStatus(422);
    }

    public function test_reject_requires_a_reviewer_and_resets_the_order_to_draft(): void
    {
        $deliveryOrder = $this->createPendingDeliveryOrderAs($this->submitter);

        $denied = $this->actingAs($this->otherSales)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/reject-approval", [
                'reason' => 'Not acceptable for this delivery',
            ]);
        $denied->assertStatus(403);
        $this->assertSame('pending', $deliveryOrder->fresh()->approval_status);

        $rejected = $this->actingAs($this->reviewer)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/reject-approval", [
                'reason' => 'Customer address is incomplete',
            ]);
        $rejected->assertStatus(200, $rejected->getContent());

        $deliveryOrder->refresh();
        $this->assertSame('rejected', $deliveryOrder->approval_status);
        $this->assertSame('draft', $deliveryOrder->status);

        $again = $this->actingAs($this->reviewer)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/reject-approval", [
                'reason' => 'Second rejection attempt',
            ]);
        $again->assertStatus(422);
    }

    public function test_admin_who_did_not_submit_can_approve(): void
    {
        $deliveryOrder = $this->createPendingDeliveryOrderAs($this->submitter);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/approve");

        $response->assertStatus(200, $response->getContent());
        $this->assertSame('approved', $deliveryOrder->fresh()->approval_status);
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
