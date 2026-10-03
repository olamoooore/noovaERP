<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\DeliveryOrderService;
use Modules\Sales\Services\SalesOrderService;
use Tests\TenantTestCase;

/**
 * #458 (DO6): the remaining-quantity check only subtracted quantity_delivered,
 * which is incremented on COMPLETION — so several draft/confirmed delivery
 * orders each passed the check, and completion never re-checked. Two open
 * delivery orders could ship more than ordered, deduct extra stock and post
 * extra COGS.
 *
 * Guard: open (non-cancelled, not-done) delivery orders count against the
 * remainder at creation, and completion re-validates under a row lock on the
 * sales order lines.
 */
class DeliveryOrderOverDeliveryTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = (string) \Illuminate\Support\Str::uuid();
        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $tenantId,
            'name' => 'DO Over-delivery Tenant',
            'email' => 'do-over-'.uniqid().'@test.local',
            'plan' => 'standard',
        ]));
        $this->bindTenantContextWithPermissions($tenantId);

        $this->user = User::create([
            'name' => 'DO Over-delivery User',
            'email' => 'do-over-user-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->boundTestTenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->user);

        $this->customer = Customer::factory()->create(['name' => 'DO Over-delivery Customer']);
        $this->product = Product::factory()->create([
            'name' => 'DO Over-delivery Widget',
            'sku' => 'DOO-'.uniqid(),
            'price' => 10,
            'cost' => 5,
            'track_inventory' => false,
        ]);
        $this->warehouse = Warehouse::create([
            'name' => 'DO Over-delivery Warehouse',
            'code' => 'DOO-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function createSalesOrder(float $quantity = 100): SalesOrder
    {
        $service = app(SalesOrderService::class);

        $order = $service->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => $quantity,
                'unit_price' => 10,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        return $service->confirmOrder($order);
    }

    private function createDeliveryOrder(SalesOrder $order, float $quantity): DeliveryOrder
    {
        return app(DeliveryOrderService::class)->createDeliveryOrder($order, [
            'warehouse_id' => $this->warehouse->id,
            'lines' => [[
                'sales_order_line_id' => $order->lines->first()->id,
                'quantity_delivered' => $quantity,
            ]],
            'created_by' => $this->user->id,
        ]);
    }

    public function test_two_open_delivery_orders_cannot_both_take_the_full_quantity(): void
    {
        $order = $this->createSalesOrder(100);

        $first = $this->createDeliveryOrder($order, 100);
        $this->assertSame('draft', $first->status);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Only 0 remaining to deliver');

        $this->createDeliveryOrder($order, 100);
    }

    public function test_open_partial_deliveries_still_allow_filling_the_remainder(): void
    {
        $order = $this->createSalesOrder(100);
        $service = app(DeliveryOrderService::class);

        $first = $this->createDeliveryOrder($order, 60);
        $second = $this->createDeliveryOrder($order, 40);
        $this->assertSame('draft', $second->status);

        $service->completeDeliveryOrder($first);
        $service->completeDeliveryOrder($second->fresh());

        $soLine = $order->lines()->first()->fresh();
        $this->assertSame(100.0, (float) $soLine->quantity_delivered);
        $this->assertSame('done', $second->fresh()->status);
    }

    public function test_completion_revalidates_so_delivered_never_exceeds_ordered(): void
    {
        $order = $this->createSalesOrder(100);
        $service = app(DeliveryOrderService::class);

        $first = $this->createDeliveryOrder($order, 60);
        $second = $this->createDeliveryOrder($order, 40);

        // Simulate a stale/invalid open state: the second draft now claims
        // more than the remainder. Completion must re-validate under the lock
        // instead of blindly adding to quantity_delivered.
        $second->lines()->update(['quantity_delivered' => 60]);

        $service->completeDeliveryOrder($first);
        $this->assertSame(60.0, (float) $order->lines()->first()->fresh()->quantity_delivered);

        $thrown = null;

        try {
            $service->completeDeliveryOrder($second->fresh());
        } catch (\Exception $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Completing a delivery that would exceed the ordered quantity must be refused.');
        $this->assertStringContainsString('would deliver 120 of 100 ordered', $thrown->getMessage());
        $this->assertSame(60.0, (float) $order->lines()->first()->fresh()->quantity_delivered, 'The refused completion must not change delivered quantity.');
        $this->assertNotSame('done', $second->fresh()->status, 'The refused delivery order must not become done.');
    }
}
