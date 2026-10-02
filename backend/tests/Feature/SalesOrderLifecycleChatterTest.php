<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Features\ActivityLogging\Models\ActivityLogEntry;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;
use Tests\TenantTestCase;

/**
 * Issue #377 (O8): the confirm/allocate/fulfil chatter calls sat AFTER the
 * `return` inside their transaction closures, so they never ran and the
 * order's History tab stayed empty for the three transitions (cancel and
 * invoice logged because their calls come before the return).
 */
class SalesOrderLifecycleChatterTest extends TenantTestCase
{
    protected User $sales;

    protected Customer $customer;

    protected Product $product;

    protected Warehouse $warehouse;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->sales = User::factory()->create();
        $this->sales->assignRole(UserRole::SALES->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->sales->id,
            'tenant_id' => $this->tenantId,
            'role' => 'sales',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 100000]);

        $this->product = Product::factory()->create([
            'name' => 'Chatter Widget',
            'sku' => 'CHAT-'.Str::upper(Str::random(6)),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        $this->warehouse = Warehouse::factory()->create(['is_default' => true]);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
    }

    private function draftOrder(): SalesOrder
    {
        return app(SalesOrderService::class)->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->sales->id,
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

    private function assertChatter(SalesOrder $order, string $needle): void
    {
        $found = ActivityLogEntry::query()
            ->where('log_name', 'chatter')
            ->where('subject_type', SalesOrder::class)
            ->where('subject_id', $order->id)
            ->where('event', 'status_changed')
            ->where('description', 'like', $needle.'%')
            ->count();

        $this->assertSame(1, $found, "the chatter must contain an entry starting with '{$needle}'");
    }

    public function test_confirm_allocate_and_fulfil_are_mirrored_to_the_chatter(): void
    {
        $order = $this->draftOrder();

        $this->actingAs($this->sales);
        $service = app(SalesOrderService::class);

        $service->confirmOrder($order);
        $this->assertChatter($order, 'Confirmed sales order');

        $service->allocateOrder($order->fresh());
        $this->assertChatter($order, 'Allocated stock for sales order');

        $service->fulfilOrder($order->fresh());
        $this->assertChatter($order, 'Fulfilled sales order');
    }
}
