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
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #380 (O11): "Allocate" copied quantity into quantity_allocated with no
 * availability check. Allocation must reflect the real reserved / available
 * quantity per line, with partial allocation supported.
 */
class Issue380SalesOrderAllocationAvailabilityTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    private Product $service;

    private Warehouse $warehouse;

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
            'name' => 'Issue380 Tenant',
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
            'name' => 'Issue380 Widget',
            'sku' => 'I380-'.uniqid(),
            'track_inventory' => true,
            'price' => 100,
            'cost' => 40,
        ]);
        $this->service = Product::factory()->create([
            'name' => 'Issue380 Service',
            'sku' => 'I380S-'.uniqid(),
            'track_inventory' => false,
            'price' => 50,
            'cost' => 0,
        ]);
        $this->warehouse = Warehouse::create(['name' => 'I380 WH', 'code' => 'I380-'.uniqid(), 'is_active' => true]);
    }

    private function stock(float $quantity): void
    {
        StockLevel::create(['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => $quantity]);
    }

    private function confirmedOrder(float $stockedQty, float $serviceQty = 0): int
    {
        $lines = [[
            'product_id' => $this->product->id,
            'description' => 'Stocked line',
            'quantity' => $stockedQty,
            'unit_price' => 100,
            'tax_rate' => 0,
        ]];
        if ($serviceQty > 0) {
            $lines[] = [
                'product_id' => $this->service->id,
                'description' => 'Service line',
                'quantity' => $serviceQty,
                'unit_price' => 50,
                'tax_rate' => 0,
            ];
        }

        $create = $this->postJson('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => $lines,
        ])->assertCreated();
        $orderId = (int) $create->json('id');

        $this->postJson("/api/v1/sales/orders/{$orderId}/confirm")->assertOk();

        return $orderId;
    }

    private function line(int $orderId, int $productId): SalesOrderLine
    {
        return SalesOrderLine::where('sales_order_id', $orderId)->where('product_id', $productId)->firstOrFail();
    }

    private function reservedQuantity(): float
    {
        return (float) StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->value('reserved_quantity');
    }

    public function test_fully_reserved_line_allocates_its_reserved_quantity(): void
    {
        $this->stock(10);
        $orderId = $this->confirmedOrder(3);
        $this->assertEqualsWithDelta(3.0, $this->reservedQuantity(), 0.0001);

        $this->postJson("/api/v1/sales/orders/{$orderId}/allocate")->assertOk()
            ->assertJsonPath('order.status', 'allocated');

        $this->assertEqualsWithDelta(3.0, (float) $this->line($orderId, $this->product->id)->quantity_allocated, 0.0001);
        $this->assertEqualsWithDelta(3.0, $this->reservedQuantity(), 0.0001, 'Allocation must not double-reserve');
    }

    public function test_short_stock_allocates_only_what_is_available(): void
    {
        // 4 on hand, 10 ordered: the all-or-nothing confirm reservation fails,
        // the line is a backorder. Allocation takes the 4 that exist.
        $this->stock(4);
        $orderId = $this->confirmedOrder(10, 2);
        $this->assertEqualsWithDelta(0.0, $this->reservedQuantity(), 0.0001);

        $this->postJson("/api/v1/sales/orders/{$orderId}/allocate")->assertOk()
            ->assertJsonPath('order.status', 'allocated');

        $stocked = $this->line($orderId, $this->product->id);
        $this->assertEqualsWithDelta(4.0, (float) $stocked->quantity_allocated, 0.0001,
            'Only the 4 available units can be allocated');
        $this->assertEqualsWithDelta(6.0, (float) $stocked->backorder_quantity, 0.0001);
        $this->assertEqualsWithDelta(4.0, $this->reservedQuantity(), 0.0001,
            'The allocated units are held by a real reservation');
        $this->assertSame(1, StockReservation::where([
            'source_document_type' => 'SalesOrder',
            'source_document_id' => $orderId,
            'source_document_line_id' => $stocked->id,
        ])->whereIn('status', ['open', 'allocated'])->count());

        // A non-stock line has nothing to check against and allocates in full.
        $this->assertEqualsWithDelta(2.0, (float) $this->line($orderId, $this->service->id)->quantity_allocated, 0.0001);
    }

    public function test_nothing_available_refuses_allocation(): void
    {
        $this->stock(0);
        $orderId = $this->confirmedOrder(5);

        $this->postJson("/api/v1/sales/orders/{$orderId}/allocate")->assertStatus(422);

        $this->assertSame('confirmed', SalesOrder::findOrFail($orderId)->status);
        $this->assertEqualsWithDelta(0.0, (float) $this->line($orderId, $this->product->id)->quantity_allocated, 0.0001);
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
