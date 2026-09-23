<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Fulfillment\Models\FulfillmentProvider;
use Modules\Fulfillment\Models\Shipment;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #147 — Sales delivery orders and Fulfillment shipments were parallel,
 * non-communicating systems despite the declared module dependency. This test
 * proves the explicit integration: create a shipment for a delivery order,
 * link the records, and keep the order's carrier/tracking current.
 */
class HR147DeliveryOrderShipmentIntegrationTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    private FulfillmentProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '14714714-7283-9405-0617-283950617283',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'delivery_orders', 'delivery_order_lines', 'customers', 'products',
            'fulfillment_providers', 'shipments', 'shipment_lines', 'company_settings',
        ]);

        // Issue #146 keeps simulated carriers opt-in; enable them for this
        // integration proof.
        config(['fulfillment.simulated_carriers_enabled' => true]);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create(['name' => 'HR147 Customer']);
        $this->product = Product::factory()->create(['name' => 'HR147 Widget', 'weight' => 1.5]);

        $this->provider = FulfillmentProvider::create([
            'tenant_id' => $tenant->id,
            'carrier_code' => 'dhl',
            'name' => 'HR147 DHL',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function deliveryOrder(): DeliveryOrder
    {
        $order = DeliveryOrder::create([
            'delivery_order_number' => DeliveryOrder::generateNumber(),
            'sales_order_id' => null,
            'customer_id' => $this->customer->id,
            'status' => 'confirmed',
            'source' => 'standalone',
            'delivery_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        DeliveryOrderLine::create([
            'delivery_order_id' => $order->id,
            'description' => 'HR147 line',
            'product_id' => $this->product->id,
            'quantity_ordered' => 2,
            'quantity_delivered' => 0,
            'unit_price' => 10,
            'line_total' => 20,
        ]);

        return $order;
    }

    public function test_a_shipment_is_created_for_the_delivery_order_and_linked_back(): void
    {
        $order = $this->deliveryOrder();

        $response = $this->actingAs($this->user)->postJson(
            '/api/v1/fulfillment/delivery-orders/'.$order->id.'/shipment'
        );

        $this->assertSame(201, $response->status(), (string) ($response->json('error') ?? $response->getContent()));

        $shipment = Shipment::findOrFail($response->json('data.id'));
        $this->assertSame((string) $order->id, (string) $shipment->order_id);
        $this->assertSame('delivery_order', $shipment->order_type);
        $this->assertNotNull($shipment->tracking_number);
        $this->assertSame(1, $shipment->lines()->count(), 'DO lines must become shipment lines.');

        // The delivery order now carries the real carrier/tracking data.
        $fresh = $order->fresh();
        $this->assertSame('dhl', $fresh->carrier);
        $this->assertSame($shipment->tracking_number, $fresh->tracking_number);
    }

    public function test_shipment_tracking_updates_propagate_to_the_delivery_order(): void
    {
        $order = $this->deliveryOrder();

        $this->actingAs($this->user)
            ->postJson('/api/v1/fulfillment/delivery-orders/'.$order->id.'/shipment')
            ->assertStatus(201);

        $shipment = Shipment::where('order_id', (string) $order->id)->firstOrFail();

        $shipment->update(['tracking_number' => 'DHL-UPDATED-0001']);

        $this->assertSame('DHL-UPDATED-0001', $order->fresh()->tracking_number);
    }

    public function test_creating_a_shipment_without_a_provider_is_rejected(): void
    {
        $this->provider->update(['is_default' => false]);

        $order = $this->deliveryOrder();

        $this->actingAs($this->user)
            ->postJson('/api/v1/fulfillment/delivery-orders/'.$order->id.'/shipment')
            ->assertStatus(422);
    }
}
