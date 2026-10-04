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
use Modules\Sales\Services\SalesOrderService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #379 (O10): reserveStockForOrder() passed (int) round(quantity) — 2.5
 * reserved 3 and 0.4 reserved nothing — and a failed reservation was only a
 * log line, so the user and the list never saw the backorder.
 */
class SalesOrderBackorderFlagTest extends TenantTestCase
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
                'name' => 'SO Backorder Tenant',
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

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 1000000]);
        $this->warehouse = Warehouse::create(['name' => 'BO Warehouse', 'code' => 'BO-'.uniqid(), 'is_active' => true]);
        $this->product = Product::factory()->create([
            'name' => 'Backorder Widget',
            'sku' => 'BOW-'.uniqid(),
            'track_inventory' => true,
            'price' => 100,
            'cost' => 10,
        ]);
    }

    private function stock(float $quantity): void
    {
        StockLevel::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);
    }

    private function draftOrder(float $quantity): SalesOrder
    {
        return app(SalesOrderService::class)->createOrder([
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
    }

    public function test_fractional_quantity_is_reserved_exactly(): void
    {
        $this->stock(10);

        $order = $this->draftOrder(2.5);

        $response = $this->actingAs($this->admin)->postJson("/api/v1/sales/orders/{$order->id}/confirm");
        $response->assertOk();

        $reservation = StockReservation::where('source_document_type', 'SalesOrder')
            ->where('source_document_id', $order->id)
            ->firstOrFail();

        $this->assertSame(2.5, round((float) $reservation->quantity, 4), '2.5 must reserve 2.5 — not 3 (or 0).');

        $line = $order->lines()->first()->fresh();
        $this->assertSame(0.0, round((float) $line->backorder_quantity, 4));
        $this->assertFalse((bool) $line->is_backordered);
    }

    public function test_insufficient_stock_marks_the_line_as_a_backorder(): void
    {
        $this->stock(2);

        $order = $this->draftOrder(4);

        $response = $this->actingAs($this->admin)->postJson("/api/v1/sales/orders/{$order->id}/confirm");
        $response->assertOk();

        // Nothing was reserved (all-or-nothing), so the whole line is on backorder.
        $this->assertSame(0, StockReservation::where('source_document_type', 'SalesOrder')
            ->where('source_document_id', $order->id)
            ->count());

        $line = $order->lines()->first()->fresh();
        $this->assertSame(4.0, round((float) $line->backorder_quantity, 4), 'The unreserved quantity must be recorded on the line.');
        $this->assertTrue((bool) $line->is_backordered);

        // Visible in the confirm response and on the order payload (list/detail).
        $this->assertTrue((bool) $response->json('order.lines.0.is_backordered'));
        $this->assertSame(4.0, round((float) $response->json('order.lines.0.backorder_quantity'), 4));
        $this->assertTrue((bool) $response->json('order.has_backorder'));

        $detail = $this->actingAs($this->admin)->getJson("/api/v1/sales/orders/{$order->id}");
        $detail->assertOk();
        $this->assertTrue((bool) $detail->json('has_backorder'));
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
