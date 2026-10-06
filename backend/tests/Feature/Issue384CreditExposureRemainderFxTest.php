<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DocumentCurrencyResolver;
use App\Services\InvoiceService;
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
 * Issue #384 (O15): the confirm-time credit check must count, in base
 * currency, outstanding AR plus the UNINVOICED REMAINDER of every open order.
 * Previously an order with even one invoice dropped out of the committed
 * exposure entirely, and document-currency totals were compared directly
 * with the base-currency credit limit.
 */
class Issue384CreditExposureRemainderFxTest extends TenantTestCase
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
            'name' => 'Issue384 Tenant',
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
        $this->warehouse = Warehouse::create(['name' => 'I384 WH', 'code' => 'I384-'.uniqid(), 'is_active' => true]);
        $this->product = Product::factory()->create([
            'name' => 'Issue384 Service',
            'sku' => 'I384-'.uniqid(),
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
                'description' => 'Issue384 line',
                'quantity' => 1,
                'unit_price' => $line[0],
                'tax_rate' => 0,
                'invoicing_policy' => $line[1],
            ], $lines),
        ], $extra));
    }

    public function test_partially_invoiced_order_remainder_counts_against_the_limit(): void
    {
        // Order A = 8,000: 4,000 billed on ordered qty, 4,000 billed on
        // delivery (nothing delivered yet), so its invoice covers 4,000 only.
        $orderA = $this->order([[4000, 'ordered_qty'], [4000, 'delivered_qty']]);
        $this->postJson("/api/v1/sales/orders/{$orderA->id}/confirm")->assertOk();

        $invoice = app(SalesOrderService::class)->createInvoice($orderA->fresh());
        $this->assertEqualsWithDelta(4000.0, (float) $invoice->total, 0.001);
        app(InvoiceService::class)->postInvoice($invoice->fresh());
        $this->customer->refresh()->updateOutstanding();
        $this->assertEqualsWithDelta(4000.0, (float) $this->customer->fresh()->total_outstanding, 0.001,
            'The posted partial invoice is outstanding AR.');

        // Exposure = 4,000 AR + 4,000 uninvoiced remainder of A = 8,000.
        // A further 3,000 order would take it to 11,000 > 10,000.
        $orderB = $this->order([[3000, 'ordered_qty']]);
        $this->postJson("/api/v1/sales/orders/{$orderB->id}/confirm")->assertStatus(422);
        $this->assertSame('draft', $orderB->fresh()->status);

        // 1,500 fits: 8,000 + 1,500 = 9,500.
        $orderC = $this->order([[1500, 'ordered_qty']]);
        $this->postJson("/api/v1/sales/orders/{$orderC->id}/confirm")->assertOk();
    }

    public function test_foreign_currency_orders_are_converted_to_base_before_the_limit_check(): void
    {
        [$foreign, $base] = $this->foreignCurrencyWithRate(1.5);

        // Foreign order: 5,000 foreign = 7,500 base — within the 10,000 limit.
        $orderA = $this->order([[5000, 'ordered_qty']], ['currency_code' => $foreign]);
        $this->assertSame($base, $orderA->base_currency_code);
        $this->assertEqualsWithDelta(1.5, (float) $orderA->exchange_rate, 0.000001);
        $this->postJson("/api/v1/sales/orders/{$orderA->id}/confirm")->assertOk();

        // A base-currency 3,000 order: 7,500 + 3,000 = 10,500 > 10,000. Read
        // in document currency it would have been 5,000 + 3,000 = 8,000.
        $orderB = $this->order([[3000, 'ordered_qty']]);
        $this->postJson("/api/v1/sales/orders/{$orderB->id}/confirm")->assertStatus(422);
        $this->assertSame('draft', $orderB->fresh()->status);
    }

    public function test_the_order_being_confirmed_is_converted_to_base(): void
    {
        [$foreign] = $this->foreignCurrencyWithRate(1.5);
        $this->customer->update(['credit_limit' => 7000]);

        // 5,000 foreign = 7,500 base > 7,000 limit.
        $order = $this->order([[5000, 'ordered_qty']], ['currency_code' => $foreign]);
        $this->postJson("/api/v1/sales/orders/{$order->id}/confirm")->assertStatus(422);
        $this->assertSame('draft', $order->fresh()->status);
    }

    /**
     * Test fixture: one stored platform exchange rate (foreign → base) that
     * the document snapshot resolves through ExchangeRateService.
     *
     * @return array{0: string, 1: string}
     */
    private function foreignCurrencyWithRate(float $rate): array
    {
        $base = DocumentCurrencyResolver::baseFor(null, $this->customer->id);
        $foreign = $base === 'EUR' ? 'GBP' : 'EUR';

        ExchangeRate::create([
            'from_currency' => $foreign,
            'to_currency' => $base,
            'rate' => $rate,
            'effective_date' => now()->subDay()->toDateString(),
            'source' => 'manual',
        ]);

        return [$foreign, $base];
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
