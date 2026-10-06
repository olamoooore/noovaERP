<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
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
use Modules\Sales\Models\DeliveryOrder;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #459 (DO7): a delivery order could be invoiced from any status except
 * cancelled — draft/confirmed (never shipped) documents billed their planned
 * `quantity_delivered` — and a draft/confirmed order that already carried an
 * invoice could still be cancelled, leaving a billed-but-never-shipped invoice.
 *
 * Real HTTP through the router: invoicing only once `done`, unless the tenant
 * enables `sales.delivery.bill_before_delivery`, and cancellation refused while
 * an issued invoice is linked (draft links are detached instead).
 */
class Issue459DeliveryOrderBillingStatusGuardTest extends TenantTestCase
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
                'name' => 'DO Billing Status Guard Tenant',
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
        Setting::setValue('sales.delivery_order.default_requires_approval', false);

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 1000000]);
        $this->warehouse = Warehouse::create([
            'name' => 'Billing Guard Warehouse',
            'code' => 'BGW-'.uniqid(),
            'is_active' => true,
        ]);
        $this->product = Product::factory()->create([
            'name' => 'Billing Guard Widget',
            'sku' => 'BGW-'.uniqid(),
            'track_inventory' => false,
            'price' => 100,
            'cost' => 40,
        ]);
    }

    private function standaloneDeliveryOrder(): DeliveryOrder
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/sales/delivery-orders', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity_delivered' => 3,
                'unit_price' => 100,
            ]],
        ]);

        $response->assertStatus(201, $response->getContent());

        return DeliveryOrder::with('lines')->findOrFail($response->json('data.id'));
    }

    private function confirm(DeliveryOrder $deliveryOrder): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/confirm")
            ->assertOk();
    }

    private function complete(DeliveryOrder $deliveryOrder): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/complete")
            ->assertOk();
    }

    private function createInvoice(DeliveryOrder $deliveryOrder): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/create-invoice");
    }

    public function test_a_draft_delivery_order_cannot_be_invoiced_by_default(): void
    {
        $deliveryOrder = $this->standaloneDeliveryOrder();

        $response = $this->createInvoice($deliveryOrder);

        $response->assertStatus(422);
        $this->assertStringContainsString('done', strtolower((string) $response->json('message')));

        $this->assertDatabaseCount('invoices', 0);
        $this->assertNull($deliveryOrder->fresh()->invoice_id, 'A refused invoice must not link the delivery order.');
    }

    public function test_a_confirmed_delivery_order_cannot_be_invoiced_by_default(): void
    {
        $deliveryOrder = $this->standaloneDeliveryOrder();
        $this->confirm($deliveryOrder);

        $response = $this->createInvoice($deliveryOrder);

        $response->assertStatus(422);
        $this->assertStringContainsString('done', strtolower((string) $response->json('message')));

        $this->assertDatabaseCount('invoices', 0);
        $this->assertNull($deliveryOrder->fresh()->invoice_id);
    }

    public function test_a_done_delivery_order_can_be_invoiced(): void
    {
        $deliveryOrder = $this->standaloneDeliveryOrder();
        $this->confirm($deliveryOrder);
        $this->complete($deliveryOrder);

        $response = $this->createInvoice($deliveryOrder);

        $response->assertStatus(201, $response->getContent());

        $invoiceId = $response->json('data.id');
        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'customer_id' => $this->customer->id]);
        $this->assertSame($invoiceId, $deliveryOrder->fresh()->invoice_id);
    }

    public function test_bill_before_delivery_policy_allows_invoicing_a_draft_delivery_order(): void
    {
        Setting::setValue('sales.delivery.bill_before_delivery', true);

        $deliveryOrder = $this->standaloneDeliveryOrder();

        $response = $this->createInvoice($deliveryOrder);

        $response->assertStatus(201, $response->getContent());
        $this->assertNotNull($deliveryOrder->fresh()->invoice_id);
    }

    public function test_bill_before_delivery_policy_allows_invoicing_a_confirmed_delivery_order(): void
    {
        Setting::setValue('sales.delivery.bill_before_delivery', true);

        $deliveryOrder = $this->standaloneDeliveryOrder();
        $this->confirm($deliveryOrder);

        $response = $this->createInvoice($deliveryOrder);

        $response->assertStatus(201, $response->getContent());
        $this->assertNotNull($deliveryOrder->fresh()->invoice_id);
    }

    public function test_show_exposes_can_invoice_per_status_and_policy(): void
    {
        $deliveryOrder = $this->standaloneDeliveryOrder();

        $this->actingAs($this->admin)
            ->getJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}")
            ->assertOk()
            ->assertJsonPath('can_invoice', false);

        $this->confirm($deliveryOrder);
        $this->complete($deliveryOrder);

        $this->actingAs($this->admin)
            ->getJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}")
            ->assertOk()
            ->assertJsonPath('can_invoice', true);

        Setting::setValue('sales.delivery.bill_before_delivery', true);

        $second = $this->standaloneDeliveryOrder();

        $this->actingAs($this->admin)
            ->getJson("/api/v1/sales/delivery-orders/{$second->id}")
            ->assertOk()
            ->assertJsonPath('can_invoice', true);
    }

    public function test_cancelling_a_delivery_order_with_a_non_draft_invoice_is_refused(): void
    {
        Setting::setValue('sales.delivery.bill_before_delivery', true);

        $deliveryOrder = $this->standaloneDeliveryOrder();
        $invoiceId = $this->createInvoice($deliveryOrder)->assertStatus(201)->json('data.id');

        Invoice::findOrFail($invoiceId)->update(['status' => 'posted', 'lifecycle_status' => 'posted']);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/cancel", ['reason' => 'Customer changed their mind']);

        $response->assertStatus(422);
        $this->assertStringContainsString('credit note', strtolower((string) $response->json('message')));

        $this->assertSame('draft', $deliveryOrder->fresh()->status, 'A refused cancellation must not mutate the order.');
        $this->assertSame($invoiceId, $deliveryOrder->fresh()->invoice_id);
    }

    public function test_cancelling_a_delivery_order_with_only_a_draft_invoice_detaches_the_link(): void
    {
        Setting::setValue('sales.delivery.bill_before_delivery', true);

        $deliveryOrder = $this->standaloneDeliveryOrder();
        $invoiceId = $this->createInvoice($deliveryOrder)->assertStatus(201)->json('data.id');

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/cancel", ['reason' => 'Not shipped']);

        $response->assertOk();

        $this->assertSame('cancelled', $deliveryOrder->fresh()->status);
        $this->assertNull($deliveryOrder->fresh()->invoice_id, 'The stale draft link must be detached.');
        $this->assertDatabaseMissing('invoice_delivery_orders', [
            'delivery_order_id' => $deliveryOrder->id,
            'invoice_id' => $invoiceId,
        ]);
        $this->assertSame('draft', Invoice::findOrFail($invoiceId)->status, 'A draft invoice is not posted and must not be deleted.');
    }

    public function test_cancelling_a_completed_delivery_order_with_a_posted_invoice_is_refused(): void
    {
        $deliveryOrder = $this->standaloneDeliveryOrder();
        $this->confirm($deliveryOrder);
        $this->complete($deliveryOrder);

        $invoiceId = $this->createInvoice($deliveryOrder)->assertStatus(201)->json('data.id');
        Invoice::findOrFail($invoiceId)->update(['status' => 'posted', 'lifecycle_status' => 'posted']);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/cancel", ['reason' => 'Shipped in error']);

        $response->assertStatus(422);
        $this->assertSame('done', $deliveryOrder->fresh()->status, 'A completed delivery must not be cancelled.');
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
