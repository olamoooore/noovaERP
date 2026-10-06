<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Feature;
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
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #376 acceptance criterion: bill-by-delivered is changeable only
 * before the first invoice, with an audit entry.
 *
 * Exercised through the real route POST /api/v1/sales/orders/{id}/bill-by-delivered
 * (router -> middleware -> controller):
 *   - before any invoice the switch changes and writes a #895
 *     `sales_order_updated` audit row with the before/after value;
 *   - once the order has a non-cancelled invoice (or invoiced quantity) the
 *     change is refused with 422 and nothing is written;
 *   - a cancelled invoice does not count as "the first invoice".
 */
class Issue376BillByDeliveredGuardTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();
        $this->mockFeatureGate(true);

        $this->tenantId = (string) Str::uuid();
        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue 376 Bill By Delivered Tenant',
            'email' => 'issue376-'.uniqid().'@test.local',
            'plan' => 'standard',
        ]));
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Setting::setValue('sales.orders.auto_lock_on_confirm', false);

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

        $this->customer = Customer::factory()->create([
            'payment_terms' => 30,
            'credit_limit' => 100000,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Issue 376 Widget',
            'sku' => 'I376-'.uniqid(),
            'price' => 10,
            'cost' => 4,
            'track_inventory' => false,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Issue 376 Warehouse',
            'code' => 'I376-'.uniqid(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin);
    }

    private function createConfirmedOrder(): SalesOrder
    {
        $response = $this->postJson('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 10,
                'unit_price' => 10,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated();
        $orderId = (int) $response->json('id');

        $this->postJson("/api/v1/sales/orders/{$orderId}/confirm")->assertOk();

        return SalesOrder::with('lines')->findOrFail($orderId);
    }

    private function auditRows(SalesOrder $order)
    {
        return AuditLog::query()
            ->where('reference_type', 'sales_order')
            ->where('reference_id', $order->id)
            ->where('operation', 'sales_order_updated')
            ->orderBy('id')
            ->get();
    }

    public function test_change_before_first_invoice_is_allowed_and_audited(): void
    {
        $order = $this->createConfirmedOrder();
        $this->assertFalse((bool) $order->bill_by_delivered_qty);
        $auditBefore = $this->auditRows($order)->count();

        $this->postJson("/api/v1/sales/orders/{$order->id}/bill-by-delivered", [
            'bill_by_delivered_qty' => true,
        ])->assertOk()->assertJsonPath('bill_by_delivered_qty', true);

        $this->assertTrue((bool) $order->fresh()->bill_by_delivered_qty);

        $rows = $this->auditRows($order);
        $this->assertCount($auditBefore + 1, $rows, 'The change must write exactly one audit row.');
        $log = $rows->last();
        $this->assertFalse((bool) ($log->before_state['fields']['bill_by_delivered_qty'] ?? null));
        $this->assertTrue((bool) ($log->after_state['fields']['bill_by_delivered_qty'] ?? null));
        $this->assertArrayHasKey('bill_by_delivered_qty', $log->before_state['fields'] ?? []);
    }

    public function test_change_after_first_invoice_is_refused(): void
    {
        $order = $this->createConfirmedOrder();

        $this->postJson("/api/v1/sales/orders/{$order->id}/invoice")->assertCreated();
        $auditBefore = $this->auditRows($order)->count();

        $response = $this->postJson("/api/v1/sales/orders/{$order->id}/bill-by-delivered", [
            'bill_by_delivered_qty' => true,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('invoice', strtolower((string) $response->json('message')));
        $this->assertFalse((bool) $order->fresh()->bill_by_delivered_qty, 'The setting must not change once invoiced.');
        $this->assertCount($auditBefore, $this->auditRows($order), 'A refused change writes no audit row.');
    }

    public function test_invoiced_quantity_alone_blocks_the_change(): void
    {
        $order = $this->createConfirmedOrder();
        // Invoiced quantity recorded on the line (e.g. a delivery-order
        // invoice path) counts as invoiced even without a link row.
        $order->lines->first()->update(['quantity_invoiced' => 2]);

        $this->postJson("/api/v1/sales/orders/{$order->id}/bill-by-delivered", [
            'bill_by_delivered_qty' => true,
        ])->assertStatus(422);

        $this->assertFalse((bool) $order->fresh()->bill_by_delivered_qty);
    }

    public function test_cancelled_invoice_does_not_block_the_change(): void
    {
        $order = $this->createConfirmedOrder();

        $invoice = Invoice::factory()->create([
            'customer_id' => $this->customer->id,
            'sales_order_id' => $order->id,
            'status' => 'cancelled',
            'created_by' => $this->admin->id,
        ]);
        DB::table('sales_invoice_links')->insert([
            'sales_order_id' => $order->id,
            'invoice_id' => $invoice->id,
            'link_type' => 'primary',
            'link_state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/sales/orders/{$order->id}/bill-by-delivered", [
            'bill_by_delivered_qty' => true,
        ])->assertOk();

        $this->assertTrue((bool) $order->fresh()->bill_by_delivered_qty);
    }

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(fn (string $tenantId, string $key) => [
                'key' => $key,
                'is_entitled' => $isEntitled,
                'source' => $isEntitled ? 'tier_included' : null,
                'name' => strtoupper($key),
            ]);
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

        Feature::query()->updateOrCreate(
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
