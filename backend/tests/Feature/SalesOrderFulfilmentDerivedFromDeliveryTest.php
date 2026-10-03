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
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesOrderStatusHistory;
use Tests\TenantTestCase;

/**
 * Issue #373 (O4): fulfilOrder() marked lines fulfilled with NO delivery —
 * quote -> invoice and the admin invoice fast-track ran it automatically and
 * a manual Fulfil button exposed it directly, while the stock reservation
 * stayed open forever (only delivery-order completion ever uses it up).
 *
 * Fulfilled is now DERIVED from deliveries: completing a delivery order
 * mirrors delivered quantities into quantity_fulfilled and transitions the
 * order to 'fulfilled'; invoicing and the manual button no longer do.
 */
class SalesOrderFulfilmentDerivedFromDeliveryTest extends TenantTestCase
{
    private User $admin;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
        $this->assertTenantSchemaReady([
            'quotations', 'quotation_lines', 'sales_orders', 'sales_order_lines',
            'sales_order_status_history', 'stock_reservations', 'stock_levels',
            'delivery_orders', 'delivery_order_lines', 'invoices', 'invoice_lines',
        ]);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

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

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 1000000]);
        $this->warehouse = Warehouse::factory()->create(['is_default' => true]);
        $this->product = Product::factory()->create([
            'name' => 'Tracked Fulfilment Widget',
            'sku' => 'TFW-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => true,
        ]);
        StockLevel::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 50,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Setting::setValue('sales.delivery_order.default_requires_approval', false);
    }

    private function draftQuotation(int $qty = 5): int
    {
        $quotation = \App\Models\Quotation::create([
            'customer_id' => $this->customer->id,
            'quotation_number' => 'Q-'.uniqid(),
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => $qty * 100,
            'tax_amount' => 0,
            'total' => $qty * 100,
            'created_by' => $this->admin->id,
        ]);

        \App\Models\QuotationLine::create([
            'quotation_id' => $quotation->id,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => $qty,
            'unit_price' => 100,
            'tax_rate' => 0,
            'discount_amount' => 0,
            'line_total' => $qty * 100,
        ]);

        return $quotation->id;
    }

    private function soleOrder()
    {
        $soId = DB::table('sales_orders')->orderBy('id')->value('id');

        return \Modules\Sales\Models\SalesOrder::find($soId);
    }

    public function test_quote_to_invoice_without_a_delivery_never_fulfils_and_keeps_the_reservation_open(): void
    {
        $quotationId = $this->draftQuotation(5);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotationId}/create-invoice")
            ->assertCreated();

        $order = $this->soleOrder();
        $this->assertNotNull($order, 'quote -> invoice must release a sales order');

        $line = SalesOrderLine::where('sales_order_id', $order->id)->firstOrFail();
        $this->assertSame(0.0, (float) $line->quantity_delivered, 'nothing was delivered');
        $this->assertSame(0.0, (float) $line->quantity_fulfilled, 'fulfilment must be derived from delivery — invoicing sets none (#373)');

        $this->assertSame(
            0,
            SalesOrderStatusHistory::where('sales_order_id', $order->id)->where('to_status', 'fulfilled')->count(),
            'quote -> invoice must never transition through fulfilled without a delivery (#373)'
        );
        $this->assertNotSame('fulfilled', $order->status);

        // The reservation must still be open — nothing consumed it.
        $reservation = StockReservation::where('source_document_type', 'SalesOrder')
            ->where('source_document_id', $order->id)
            ->firstOrFail();
        $this->assertContains($reservation->status, ['open', 'allocated'], 'the reservation must stay open until a delivery uses it');
        $this->assertSame(0, (int) $reservation->quantity_fulfilled);
    }

    public function test_completing_a_delivery_order_derives_fulfilment_and_uses_up_the_reservation(): void
    {
        $quotationId = $this->draftQuotation(5);

        $create = $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotationId}/create-delivery-order")
            ->assertCreated();

        $order = $this->soleOrder();
        // confirm (done in releaseQuotation) -> allocate so the lifecycle may fulfil.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/orders/{$order->id}/allocate")
            ->assertOk();

        $doId = $create->json('data.id');
        $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$doId}/confirm")
            ->assertOk();
        $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/delivery-orders/{$doId}/complete")
            ->assertOk();

        $order = $order->fresh();
        $line = SalesOrderLine::where('sales_order_id', $order->id)->firstOrFail();

        $this->assertSame(5.0, (float) $line->quantity_delivered, 'the delivery delivered the full quantity');
        $this->assertSame(5.0, (float) $line->quantity_fulfilled, 'quantity_fulfilled must mirror the delivered quantity (#373)');
        $this->assertSame('fulfilled', $order->status, 'fulfilled status must be derived from the completed delivery');

        $reservation = StockReservation::where('source_document_type', 'SalesOrder')
            ->where('source_document_id', $order->id)
            ->firstOrFail();
        $this->assertSame(5, (int) $reservation->quantity_fulfilled, 'the delivery must use up the reservation');
        $this->assertNotContains($reservation->status, ['open', 'allocated']);
    }

    public function test_the_manual_fulfil_entry_point_is_gone(): void
    {
        $quotationId = $this->draftQuotation(2);
        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotationId}/create-delivery-order")
            ->assertCreated();

        $order = $this->soleOrder();
        $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/orders/{$order->id}/allocate")
            ->assertOk();

        // AC1: the manual Fulfil button/route must be removed — a 404 means
        // orders can no longer be marked fulfilled without a delivery.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/sales/orders/{$order->id}/fulfil")
            ->assertNotFound();

        $this->assertSame('allocated', $order->fresh()->status, 'no fulfil without a delivery');
        $line = SalesOrderLine::where('sales_order_id', $order->id)->firstOrFail();
        $this->assertSame(0.0, (float) $line->quantity_fulfilled);
    }
}
