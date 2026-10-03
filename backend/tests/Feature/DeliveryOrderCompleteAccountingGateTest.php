<?php

declare(strict_types=1);

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
use Modules\Sales\Models\DeliveryOrder;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #453 (DO1): "Complete" auto-confirmed drafts, so any sales user could
 * deduct stock / post COGS while skipping the accounting.post gate that the
 * explicit confirm route enforces. The auto-confirm shortcut must re-check
 * accounting.post (RequirePermission::allows) and answer 403 otherwise.
 *
 * Router-level: postJson through middleware -> controller, not source strings.
 */
class DeliveryOrderCompleteAccountingGateTest extends TenantTestCase
{
    private User $salesRep;

    private User $manager;

    private User $accountant;

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
        foreach (['Sales', 'Manager', 'Accountant', 'Admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Tenant::withoutEvents(function () {
            return Tenant::create([
                'id' => $this->tenantId,
                'name' => 'DO Complete Gate Tenant',
                'email' => fake()->unique()->safeEmail(),
                'plan' => 'standard',
            ]);
        });

        // Sales/Manager hold sales.write but NOT accounting.post.
        $this->salesRep = User::factory()->create();
        $this->salesRep->assignRole('Sales');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('Manager');

        // Accountant holds accounting.post (the document-release capability).
        $this->accountant = User::factory()->create();
        $this->accountant->assignRole(UserRole::ACCOUNTANT->spatieName());

        // Admin holds both (super-role bypass).
        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN->spatieName());

        foreach ([
            ['user' => $this->salesRep, 'role' => 'sales'],
            ['user' => $this->manager, 'role' => 'manager'],
            ['user' => $this->accountant, 'role' => 'accountant'],
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

        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::create([
            'name' => 'DO Gate Warehouse',
            'code' => 'DOG-'.uniqid(),
            'is_active' => true,
        ]);
        $this->product = Product::factory()->create([
            'name' => 'DO Gate Widget',
            'sku' => 'DOG-'.uniqid(),
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
                'description' => 'DO gate line',
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

    public function test_sales_user_cannot_complete_a_draft_delivery_order(): void
    {
        $doId = $this->createDeliveryOrderAs($this->salesRep);

        $response = $this->actingAs($this->salesRep)->postJson("/api/v1/sales/delivery-orders/{$doId}/complete");

        $response->assertStatus(403);
        $this->assertSame('draft', DeliveryOrder::find($doId)->status, 'A refused completion must not auto-confirm the draft.');
    }

    public function test_manager_without_accounting_post_cannot_complete_a_draft_delivery_order(): void
    {
        $doId = $this->createDeliveryOrderAs($this->salesRep);

        $response = $this->actingAs($this->manager)->postJson("/api/v1/sales/delivery-orders/{$doId}/complete");

        $response->assertStatus(403);
        $this->assertSame('draft', DeliveryOrder::find($doId)->status);
    }

    public function test_authorised_user_can_complete_a_draft_delivery_order(): void
    {
        $doId = $this->createDeliveryOrderAs($this->salesRep);

        $response = $this->actingAs($this->admin)->postJson("/api/v1/sales/delivery-orders/{$doId}/complete");

        $response->assertStatus(200, $response->getContent());
        $this->assertSame('done', DeliveryOrder::find($doId)->status);
    }

    public function test_sales_user_can_still_complete_an_already_confirmed_delivery_order(): void
    {
        $doId = $this->createDeliveryOrderAs($this->salesRep);

        $confirm = $this->actingAs($this->accountant)->postJson("/api/v1/sales/delivery-orders/{$doId}/confirm", []);
        $confirm->assertStatus(200, $confirm->getContent());

        $response = $this->actingAs($this->salesRep)->postJson("/api/v1/sales/delivery-orders/{$doId}/complete");

        $response->assertStatus(200, $response->getContent());
        $this->assertSame('done', DeliveryOrder::find($doId)->status);
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
