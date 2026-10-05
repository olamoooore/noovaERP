<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #456 (DO4) — DeliveryOrderController::createInvoice built
 * AccountingDocumentLineData without a tax rate (0% on every line), never
 * carried the delivery order's currency snapshot, discounts or payment
 * terms, and never linked the resulting invoice to the Sales Order.
 *
 * Real HTTP through the router; tax is resolved server-side by the shared
 * engine, never from the browser-sent rate.
 */
class DeliveryOrderInvoiceParityTest extends TenantTestCase
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
                'name' => 'DO Invoice Parity Tenant',
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
        Setting::setValue('tax', ['enabled' => true, 'jurisdiction' => 'MY']);

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 1000000]);
        $this->warehouse = Warehouse::create([
            'name' => 'Parity Warehouse',
            'code' => 'PW-'.uniqid(),
            'is_active' => true,
        ]);

        $tax = Tax::create([
            'name' => 'MY Service Tax 8%',
            'code' => 'MY-SST-8',
            'jurisdiction' => 'MY',
            'regime' => 'SST',
            'scope' => 'output',
            'treatment' => 'standard_rated',
            'type' => 'service',
            'rate' => 8,
            'is_active' => true,
            'is_default' => true,
            'lifecycle_state' => 'ACTIVE',
            'effective_from' => now()->subYear()->toDateString(),
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Parity Widget',
            'sku' => 'PWT-'.uniqid(),
            'track_inventory' => false,
            'price' => 100,
            'cost' => 40,
            'tax_id' => $tax->id,
            'tax_rate' => 0,
            'tax_mode' => 'default',
        ]);
    }

    private function confirmedOrder(): SalesOrder
    {
        $service = app(SalesOrderService::class);

        $order = $service->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->admin->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 5,
                'unit_price' => 100,
                // The browser-sent rate is stale garbage — the invoice must
                // resolve the engine's 8% anyway.
                'tax_rate' => 0,
                'discount_amount' => 7,
            ]],
        ]);

        return $service->allocateOrder($service->confirmOrder($order));
    }

    private function deliveryOrderFor(SalesOrder $order): DeliveryOrder
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/sales/delivery-orders', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'lines' => [[
                'sales_order_line_id' => $order->lines->first()->id,
                'quantity_delivered' => 5,
            ]],
        ]);
        $response->assertStatus(201, $response->getContent());

        $deliveryOrder = DeliveryOrder::with('lines')->find($response->json('data.id'));

        // Snapshot the commercial terms the delivery was entered with: a USD
        // delivery billed after FX moved must keep its own rate, and its
        // 15-day terms must not silently become 30.
        DB::table('delivery_orders')->where('id', $deliveryOrder->id)->update([
            'currency_code' => 'USD',
            'base_currency_code' => 'MYR',
            'exchange_rate' => 4.700000,
            'payment_terms' => '15',
        ]);

        return $deliveryOrder->fresh(['lines.salesOrderLine']);
    }

    public function test_invoice_from_a_delivery_order_carries_tax_currency_discount_terms_and_order_link(): void
    {
        $order = $this->confirmedOrder();
        $deliveryOrder = $this->deliveryOrderFor($order);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$deliveryOrder->id}/create-invoice");

        $response->assertStatus(201, $response->getContent());

        $invoice = Invoice::with('lines')->find($response->json('data.id'));
        $line = $invoice->lines->first();

        // Engine-resolved tax, not the stale 0% the SO line carried.
        $this->assertSame(8.0, (float) $line->tax_rate, 'DO lines must be invoiced at the engine-resolved rate.');
        $this->assertSame(7.0, (float) $line->discount_amount, 'The originating order line discount must travel with the conversion.');

        // 5 × 100 − 7 = 493 net; 8% = 39.44 tax; 532.44 total.
        $this->assertSame(39.44, round((float) $invoice->tax_amount, 2));
        $this->assertSame(532.44, round((float) $invoice->total, 2));

        // The source document's currency snapshot, not a re-resolved default.
        $this->assertSame('USD', $invoice->currency_code);
        $this->assertSame('MYR', $invoice->base_currency_code);
        $this->assertSame(4.7, round((float) $invoice->exchange_rate, 4));

        // 15-day terms from the delivery, not the hard-coded 30.
        $this->assertSame(
            now()->toDateString() === $invoice->invoice_date->toDateString()
                ? now()->addDays(15)->toDateString()
                : $invoice->invoice_date->copy()->addDays(15)->toDateString(),
            $invoice->due_date->toDateString()
        );

        // The Sales Order link survives the conversion.
        $this->assertSame((int) $order->id, (int) $invoice->sales_order_id);
        $this->assertSame((int) $order->lines->first()->id, (int) $line->sales_order_line_id);
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
