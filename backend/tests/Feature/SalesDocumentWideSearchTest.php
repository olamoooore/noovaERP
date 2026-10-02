<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TagService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TenantTestCase;

/**
 * The sales-order and delivery-order LIST searches must be as wide as the
 * invoice list: typing a customer, a contact person, an address, a line value
 * or a tag must find the document.
 *
 * Regression: both lists only matched their document number + customer name.
 */
class SalesDocumentWideSearchTest extends TenantTestCase
{
    protected User $user;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\EnsureInventoryAccess::class);

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Sales Wide Search Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->user = User::factory()->create();
        $this->user->assignRole(UserRole::MANAGER->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'manager',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return int[]
     */
    private function searchIds(string $path, string $term): array
    {
        $response = $this->actingAs($this->user)
            ->getJson($path.'?search='.urlencode($term).'&per_page=50')
            ->assertOk();

        return collect($response->json('data'))->pluck('id')->map('intval')->all();
    }

    private function salesOrderFor(Customer $customer): SalesOrder
    {
        return SalesOrder::create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);
    }

    private function deliveryOrderFor(Customer $customer): DeliveryOrder
    {
        return DeliveryOrder::create([
            'customer_id' => $customer->id,
            'delivery_date' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_sales_order_search_matches_customer_contact_address_and_tag(): void
    {
        $customer = Customer::factory()->create(['name' => 'Astra Specialists', 'type' => 'company']);
        CustomerContact::create([
            'customer_id' => $customer->id,
            'name' => 'Tan Toh Lick',
            'contact_category' => 'doctor',
            'contact_type' => 'doctor',
        ]);
        CustomerAddress::create([
            'customer_id' => $customer->id,
            'type' => 'billing',
            'label' => 'HQ',
            'street' => '12 Jalan Kilang',
            'city' => 'Petaling Jaya',
            'country' => 'Malaysia',
        ]);
        $order = $this->salesOrderFor($customer);

        $this->assertContains($order->id, $this->searchIds('/api/v1/sales/orders', 'Tan Toh'));
        $this->assertContains($order->id, $this->searchIds('/api/v1/sales/orders', 'Petaling Jaya'));

        app(TagService::class)->attach($order, ['VIP'], $this->user->id);
        $this->assertContains($order->id, $this->searchIds('/api/v1/sales/orders', 'vip'));
    }

    public function test_sales_order_search_matches_line_contents_and_number(): void
    {
        $customer = Customer::factory()->create(['name' => 'Astra Specialists']);
        $order = $this->salesOrderFor($customer);
        SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'line_type' => 'product',
            'sequence' => 0,
            'description' => 'Widget Assembly',
            'model_number' => 'MOD-9000',
            'quantity' => 1,
            'unit_price' => 10,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'line_total' => 10,
        ]);

        $this->assertContains($order->id, $this->searchIds('/api/v1/sales/orders', 'MOD-9000'));
        $this->assertContains($order->id, $this->searchIds('/api/v1/sales/orders', $order->sales_order_number));
        $this->assertSame([], $this->searchIds('/api/v1/sales/orders', 'Nobody Contactperson Zzz'));
    }

    public function test_delivery_order_search_matches_customer_contact_and_line_contents(): void
    {
        $customer = Customer::factory()->create(['name' => 'Astra Specialists']);
        CustomerContact::create([
            'customer_id' => $customer->id,
            'name' => 'Tan Toh Lick',
            'contact_category' => 'doctor',
            'contact_type' => 'doctor',
        ]);
        $order = $this->deliveryOrderFor($customer);
        DeliveryOrderLine::create([
            'delivery_order_id' => $order->id,
            'line_type' => 'product',
            'sequence' => 0,
            'description' => 'Delivery Widget',
            'model_number' => 'DO-MOD-1',
            'lot_number' => 'LOT-XYZ-42',
            'quantity_ordered' => 1,
            'quantity_delivered' => 1,
            'unit_price' => 10,
            'line_total' => 10,
        ]);

        $this->assertContains($order->id, $this->searchIds('/api/v1/sales/delivery-orders', 'Tan Toh'));
        $this->assertContains($order->id, $this->searchIds('/api/v1/sales/delivery-orders', 'LOT-XYZ-42'));
        $this->assertContains($order->id, $this->searchIds('/api/v1/sales/delivery-orders', $order->delivery_order_number));
        $this->assertSame([], $this->searchIds('/api/v1/sales/delivery-orders', 'Nobody Contactperson Zzz'));
    }

    public function test_delivery_order_search_matches_customer_tags(): void
    {
        $customer = Customer::factory()->create(['name' => 'Astra Specialists']);
        $order = $this->deliveryOrderFor($customer);

        app(TagService::class)->attach($order, ['Urgent'], $this->user->id);

        $this->assertContains($order->id, $this->searchIds('/api/v1/sales/delivery-orders', 'urgent'));
    }
}
