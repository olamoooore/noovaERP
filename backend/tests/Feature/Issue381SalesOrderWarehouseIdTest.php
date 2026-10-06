<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
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
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockReservation;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #381 (O12): POST /sales/orders dropped warehouse_id, so stock-tracked
 * API orders were never reserved at confirm and failed at allocate.
 */
class Issue381SalesOrderWarehouseIdTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue381 Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

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

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 1000000]);
        $this->product = Product::factory()->create([
            'name' => 'Issue381 Widget',
            'sku' => 'I381-'.uniqid(),
            'track_inventory' => true,
            'price' => 100,
            'cost' => 40,
        ]);
    }

    private function stockedWarehouse(string $name, bool $default = false): Warehouse
    {
        $warehouse = Warehouse::create(['name' => $name, 'code' => 'I381-'.uniqid(), 'is_active' => true]);
        if ($default) {
            $warehouse->setAsDefault();
        }
        StockLevel::create(['product_id' => $this->product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10]);

        return $warehouse;
    }

    private function orderPayload(array $extra = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Issue381 line',
                'quantity' => 3,
                'unit_price' => 100,
                'tax_rate' => 0,
            ]],
        ], $extra);
    }

    public function test_create_confirm_allocate_succeeds_with_explicit_warehouse(): void
    {
        $this->stockedWarehouse('Default WH', true);
        $warehouse = $this->stockedWarehouse('Chosen WH');

        $create = $this->postJson('/api/v1/sales/orders', $this->orderPayload(['warehouse_id' => $warehouse->id]));
        $create->assertCreated();
        $orderId = $create->json('id');
        $this->assertSame($warehouse->id, (int) $create->json('warehouse_id'));

        $this->postJson("/api/v1/sales/orders/{$orderId}/confirm")->assertOk();

        $this->assertSame(1, StockReservation::where([
            'source_document_type' => 'SalesOrder',
            'source_document_id' => $orderId,
            'warehouse_id' => $warehouse->id,
        ])->whereIn('status', ['open', 'allocated'])->count(), 'Confirm must reserve stock in the chosen warehouse');

        $allocate = $this->postJson("/api/v1/sales/orders/{$orderId}/allocate");
        $allocate->assertOk();
        $this->assertSame('allocated', $allocate->json('order.status'));
    }

    public function test_warehouse_defaults_to_the_default_warehouse_for_stock_tracked_lines(): void
    {
        $default = $this->stockedWarehouse('Default WH', true);

        $create = $this->postJson('/api/v1/sales/orders', $this->orderPayload());
        $create->assertCreated();
        $orderId = $create->json('id');
        $this->assertSame($default->id, (int) $create->json('warehouse_id'));

        $this->postJson("/api/v1/sales/orders/{$orderId}/confirm")->assertOk();
        $this->postJson("/api/v1/sales/orders/{$orderId}/allocate")->assertOk()
            ->assertJsonPath('order.status', 'allocated');
    }

    public function test_unknown_warehouse_is_rejected(): void
    {
        $this->postJson('/api/v1/sales/orders', $this->orderPayload(['warehouse_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('warehouse_id');
    }

    private function mockFeatureGate(): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')->andReturnUsing(fn (string $tenantId, string $key) => [
            'key' => $key, 'is_entitled' => true, 'source' => 'tier_included', 'name' => strtoupper($key),
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
