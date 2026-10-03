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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockReservation;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesInvoiceLink;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\DeliveryOrderService;
use Modules\Sales\Services\SalesOrderService;
use Tests\TenantTestCase;

/**
 * #371 (O2): a partial invoice called markInvoiced() unconditionally, so the
 * order locked as `invoiced`, cancelOrder() refused the undelivered /
 * uninvoiced remainder, and every SalesInvoiceLink recorded the full order
 * value.
 *
 * Guard: partial invoicing keeps the order open (separate invoice status),
 * the remainder can be cancelled with reservations released, and the link
 * stores the invoice'''s own value.
 */
class SalesOrderPartialInvoiceRemainderTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'sales_orders', 'sales_order_lines', 'sales_order_status_history',
            'stock_reservations', 'stock_levels', 'stock_movements',
            'delivery_orders', 'delivery_order_lines',
            'invoices', 'invoice_lines', 'sales_invoice_links',
        ]);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->admin->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Setting::setValue('sales.delivery_order.default_requires_approval', false);

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 1000000]);
        $this->warehouse = Warehouse::factory()->create(['is_default' => true]);
        $this->product = Product::factory()->create([
            'name' => 'Partial Invoice Widget',
            'sku' => 'PIW-'.uniqid(),
            'price' => 100,
            'cost' => 10,
            'track_inventory' => true,
        ]);
        StockLevel::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 50,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);
    }

    private function confirmedOrder(float $quantity = 10): SalesOrder
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
                'quantity' => $quantity,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        return $service->allocateOrder($service->confirmOrder($order));
    }

    private function deliver(SalesOrder $order, float $quantity): DeliveryOrder
    {
        $service = app(DeliveryOrderService::class);

        $deliveryOrder = $service->createDeliveryOrder($order, [
            'warehouse_id' => $this->warehouse->id,
            'lines' => [[
                'sales_order_line_id' => $order->lines->first()->id,
                'quantity_delivered' => $quantity,
            ]],
            'created_by' => $this->admin->id,
        ]);

        return $service->completeDeliveryOrder($deliveryOrder);
    }

    public function test_partial_invoice_keeps_the_order_open_and_cancelling_the_remainder_releases_reservations(): void
    {
        $order = $this->confirmedOrder(10);
        $order->lines()->first()->update(['invoicing_policy' => 'delivered_qty']);

        // Deliver 4 of 10, then invoice only the delivered quantity.
        $this->deliver($order, 4);

        $invoice = app(SalesOrderService::class)->createInvoice($order->fresh('lines'));
        $this->assertSame(400.0, round((float) $invoice->total, 2));

        $order = $order->fresh('lines');
        $this->assertNotSame('invoiced', $order->status, 'A partial invoice must not lock the order.');
        $this->assertSame('partially_invoiced', $order->invoiceStatus());
        $this->assertSame(4.0, round((float) $order->lines->first()->quantity_invoiced, 4));

        $link = SalesInvoiceLink::where('sales_order_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame(400.0, round((float) $link->invoiced_value, 2), 'The link must store this invoice value, not the order total.');

        // Close the remaining 6: cancel must succeed, release the reservation
        // and leave the invoiced/delivered quantity alone.
        $cancelled = app(SalesOrderService::class)->cancelOrder($order, 'Close remaining quantity');

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame(6.0, round((float) $cancelled->lines->first()->quantity_cancelled, 4), 'Only the remainder (10 - max(delivered 4, invoiced 4)) may be cancelled.');

        $reservation = StockReservation::where('source_document_type', 'SalesOrder')
            ->where('source_document_id', $order->id)
            ->firstOrFail();
        $this->assertNotContains($reservation->status, ['open', 'allocated'], 'Cancelling the remainder must release the reservation.');
    }

    public function test_a_fully_invoiced_order_still_locks_and_refuses_cancellation(): void
    {
        $order = $this->confirmedOrder(10);

        $invoice = app(SalesOrderService::class)->createInvoice($order->fresh('lines'));
        $this->assertSame(1000.0, round((float) $invoice->total, 2));

        $order = $order->fresh('lines');
        $this->assertSame('invoiced', $order->status);
        $this->assertSame('fully_invoiced', $order->invoiceStatus());

        $link = SalesInvoiceLink::where('sales_order_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame(1000.0, round((float) $link->invoiced_value, 2));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invoiced orders cannot be cancelled');

        app(SalesOrderService::class)->cancelOrder($order, 'Should be refused');
    }
}
