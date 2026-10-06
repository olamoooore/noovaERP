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
use Modules\Sales\Services\SalesOrderService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #383 (O14): POST /sales/orders/{id}/confirm used to run a second,
 * discarded credit check in the controller (without the other open orders'
 * committed exposure) and echo that verdict as `credit_check`. The response
 * must carry the verdict the service actually gated confirmation on.
 */
class Issue383SalesOrderConfirmCreditVerdictTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

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
            'name' => 'Issue383 Tenant',
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

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 10000, 'total_outstanding' => 0]);
        $this->warehouse = Warehouse::create(['name' => 'I383 WH', 'code' => 'I383-'.uniqid(), 'is_active' => true]);
        $this->product = Product::factory()->create([
            'name' => 'Issue383 Service',
            'sku' => 'I383-'.uniqid(),
            'track_inventory' => false,
            'price' => 100,
            'cost' => 40,
        ]);
    }

    /**
     * @param  array<int, array{0: float, 1: string}>  $lines  [amount, invoicing_policy]
     */
    private function order(array $lines, array $extra = []): SalesOrder
    {
        return app(SalesOrderService::class)->createOrder(array_merge([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->admin->id,
            'lines' => array_map(fn (array $line) => [
                'product_id' => $this->product->id,
                'description' => 'Issue383 line',
                'quantity' => 1,
                'unit_price' => $line[0],
                'tax_rate' => 0,
                'invoicing_policy' => $line[1],
            ], $lines),
        ], $extra));
    }

    public function test_confirm_response_reports_the_services_credit_verdict_including_open_orders(): void
    {
        // Order A (6,000) confirmed: it is committed exposure, not yet AR.
        $orderA = $this->order([[6000, 'ordered_qty']]);
        $this->postJson("/api/v1/sales/orders/{$orderA->id}/confirm")->assertOk();

        // Order B (3,000): the service gates on 0 AR + 6,000 committed, so
        // the available credit it judged against is 10,000 - 6,000 = 4,000.
        $orderB = $this->order([[3000, 'ordered_qty']]);
        $response = $this->postJson("/api/v1/sales/orders/{$orderB->id}/confirm")->assertOk();

        $response->assertJsonPath('credit_check.approved', true);
        $this->assertEqualsWithDelta(4000.0, (float) $response->json('credit_check.availableCredit'), 0.001,
            'credit_check must be the verdict confirmation was gated on (committed orders included), not a second controller-side check.');
        $this->assertEqualsWithDelta(3000.0, (float) $response->json('credit_check.orderTotal'), 0.001);
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
