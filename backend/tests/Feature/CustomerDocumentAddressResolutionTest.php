<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SalesApprovalGate;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * ADR-003B W2 (rule R4): a document's bill-to is the customer's default
 * billing address and its ship-to is the customer's default delivery
 * (shipping) address, and an invoice's delivery address follows the same
 * ship-to rule (product-owner decision 2026-10-06). A contact person's own
 * address is only the last fallback, when the customer has no address at all
 * (kept for VEN-AT-11c/11d).
 *
 * `POST /sales/orders` sends no address fields, so every sales order's
 * address snapshot comes from DocumentSnapshotService. That service never
 * read `customer_addresses` and put the primary contact person's address on
 * the order as bill-to, while the invoice/quotation screens read the
 * customer's default billing address.
 *
 * Every test goes through the real HTTP route (router -> middleware ->
 * controller).
 */
class CustomerDocumentAddressResolutionTest extends TenantTestCase
{
    private string $tenantId;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();
        $this->mockFeatureGate();

        $this->tenantId = (string) Str::uuid();
        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Document Address Resolution Tenant',
            'email' => 'doc-address-'.uniqid().'@test.local',
            'plan' => 'standard',
        ]));
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $admin->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create([
            'name' => 'Acme Hospital',
            'type' => 'company',
            'payment_terms' => 30,
            'credit_limit' => 100000,
            'billing_address' => null,
            'shipping_address' => null,
        ]);

        // The person's own (practice) address must never become the
        // company's bill-to or ship-to.
        $this->customer->customerContacts()->create([
            'name' => 'Dr Tan',
            'email' => 'tan@acme.test',
            'is_primary' => true,
            'street' => '7 Clinic Lane',
            'city' => 'Ipoh',
            'state' => 'Perak',
            'zip' => '30000',
            'country' => 'MY',
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Address Test Widget',
            'sku' => 'ADDR-'.uniqid(),
            'price' => 10,
            'cost' => 4,
            'track_inventory' => false,
        ]);

        $this->actingAs($admin);
    }

    public function test_sales_order_bill_to_is_the_customers_default_billing_address(): void
    {
        $this->customer->addresses()->create([
            'type' => 'billing',
            'street' => '1 Finance Road',
            'street2' => 'Level 2',
            'city' => 'Kuala Lumpur',
            'state' => 'WP',
            'zip' => '50000',
            'country' => 'MY',
            'is_default' => true,
        ]);

        $order = $this->createOrder();

        $this->assertSame([
            'address' => '1 Finance Road, Level 2',
            'city' => 'Kuala Lumpur',
            'state' => 'WP',
            'zip' => '50000',
            'country' => 'MY',
        ], $order->billing_address_snapshot, 'Bill-to must be the customer\'s default billing address, not the contact person\'s address.');
    }

    public function test_sales_order_ship_to_is_the_customers_default_shipping_address(): void
    {
        $this->customer->addresses()->create([
            'type' => 'billing',
            'street' => '1 Finance Road',
            'city' => 'Kuala Lumpur',
            'state' => 'WP',
            'zip' => '50000',
            'country' => 'MY',
            'is_default' => true,
        ]);
        $this->customer->addresses()->create([
            'type' => 'shipping',
            'street' => '22 Warehouse Way',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'zip' => '40000',
            'country' => 'MY',
            'is_default' => true,
        ]);

        $order = $this->createOrder();

        $this->assertSame([
            'address' => '22 Warehouse Way',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'zip' => '40000',
            'country' => 'MY',
        ], $order->shipping_address_snapshot, 'Ship-to must be the customer\'s default shipping address.');
    }

    public function test_invoice_delivery_address_is_the_customers_default_shipping_address(): void
    {
        $this->customer->addresses()->create([
            'type' => 'billing',
            'street' => '1 Finance Road',
            'city' => 'Kuala Lumpur',
            'state' => 'WP',
            'zip' => '50000',
            'country' => 'MY',
            'is_default' => true,
        ]);
        $this->customer->addresses()->create([
            'type' => 'shipping',
            'street' => '22 Warehouse Way',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'zip' => '40000',
            'country' => 'MY',
            'is_default' => true,
        ]);

        $order = $this->createOrder();
        $this->postJson('/api/v1/sales/orders/'.$order->id.'/confirm')->assertOk();
        $invoiceResponse = $this->postJson('/api/v1/sales/orders/'.$order->id.'/invoice');
        $invoiceResponse->assertSuccessful();

        $invoice = Invoice::findOrFail($invoiceResponse->json('id') ?? $invoiceResponse->json('invoice.id'));
        $delivery = $invoice->delivery_address_snapshot;
        $delivery = is_string($delivery) ? json_decode($delivery, true) : $delivery;

        $this->assertSame([
            'address' => '22 Warehouse Way',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'zip' => '40000',
            'country' => 'MY',
        ], $delivery, 'An invoice\'s delivery address must be the customer\'s default shipping address, not its billing address.');
    }

    private function createOrder(): SalesOrder
    {
        $response = $this->postJson('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated();

        return SalesOrder::findOrFail($response->json('id'));
    }

    private function mockFeatureGate(): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(fn (string $tenantId, string $key) => [
                'key' => $key,
                'is_entitled' => true,
                'source' => 'tier_included',
                'name' => strtoupper($key),
            ]);
        $mock->shouldReceive('all')
            ->andReturn([['key' => 'sales', 'source' => 'tier_included', 'name' => 'SALES']]);
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
