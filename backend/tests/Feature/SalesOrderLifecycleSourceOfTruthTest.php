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
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\OrderLifecycleService;
use Modules\Sales\Services\SalesOrderService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #378 (O9): the SalesOrder model carried a stale copy of the transition map
 * that disagreed with OrderLifecycleService on confirmed/allocated→invoiced,
 * fulfilled→cancelled, invoiced→invoiced and cancelled→draft. The model now
 * delegates, and the canonical map is exposed to the UI through
 * `allowed_transitions` (plus the #371 `invoice_status`).
 */
class SalesOrderLifecycleSourceOfTruthTest extends TenantTestCase
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
                'name' => 'SO State Machine Tenant',
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
        $this->warehouse = Warehouse::create(['name' => 'SM Warehouse', 'code' => 'SM-'.uniqid(), 'is_active' => true]);
        $this->product = Product::factory()->create([
            'name' => 'State Machine Widget',
            'sku' => 'SMW-'.uniqid(),
            'track_inventory' => false,
            'price' => 100,
            'cost' => 40,
        ]);
    }

    public function test_the_model_and_the_lifecycle_service_agree_on_every_transition(): void
    {
        $statuses = ['draft', 'confirmed', 'allocated', 'fulfilled', 'invoiced', 'cancelled'];

        foreach ($statuses as $from) {
            $order = (new SalesOrder)->forceFill(['status' => $from]);

            foreach ($statuses as $to) {
                $this->assertSame(
                    OrderLifecycleService::canTransition($from, $to),
                    $order->canTransitionTo($to),
                    "The model must agree with the lifecycle map on {$from} -> {$to}.",
                );
            }
        }

        // The four edges the old model got wrong.
        $this->assertTrue((new SalesOrder)->forceFill(['status' => 'confirmed'])->canTransitionTo('invoiced'));
        $this->assertTrue((new SalesOrder)->forceFill(['status' => 'allocated'])->canTransitionTo('invoiced'));
        $this->assertTrue((new SalesOrder)->forceFill(['status' => 'fulfilled'])->canTransitionTo('cancelled'));
        $this->assertTrue((new SalesOrder)->forceFill(['status' => 'invoiced'])->canTransitionTo('invoiced'));
        $this->assertTrue((new SalesOrder)->forceFill(['status' => 'cancelled'])->canTransitionTo('draft'));
    }

    public function test_the_api_exposes_allowed_transitions_and_invoice_status(): void
    {
        $order = $this->createOrder();

        $draft = $this->actingAs($this->admin)->getJson("/api/v1/sales/orders/{$order->id}");
        $draft->assertOk();
        $this->assertEqualsCanonicalizing(['confirmed', 'cancelled'], $draft->json('allowed_transitions'));
        $this->assertSame('to_invoice', $draft->json('invoice_status'));

        app(SalesOrderService::class)->confirmOrder($order);

        $confirmed = $this->actingAs($this->admin)->getJson("/api/v1/sales/orders/{$order->id}");
        $confirmed->assertOk();
        $this->assertEqualsCanonicalizing(
            ['allocated', 'cancelled', 'invoiced'],
            $confirmed->json('allowed_transitions'),
        );
        $this->assertSame('to_invoice', $confirmed->json('invoice_status'));
    }

    private function createOrder(): SalesOrder
    {
        return app(SalesOrderService::class)->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->admin->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 2,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
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
