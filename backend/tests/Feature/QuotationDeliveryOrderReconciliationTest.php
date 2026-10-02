<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Services\DeliveryOrderService;
use Tests\TenantTestCase;

/**
 * #293: Quote → Delivery Order must create the DO FROM the quote's linked Sales
 * Order (so quantities reconcile), must not pre-deliver, and must block repeat
 * requests while an open DO already covers the remaining quantity.
 *
 * Decision (product owner, 2026-10): converting auto-releases the quote — a
 * draft/sent quote can be delivered (and the DO sent for approval) before the
 * quote is completed.
 *
 * Exercises the real router/middleware/controller chain via postJson.
 */
class QuotationDeliveryOrderReconciliationTest extends TenantTestCase
{
    protected User $admin;

    protected Customer $customer;

    protected Product $product;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);
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
        $this->product = Product::factory()->create([
            'name' => 'Reconcile Widget',
            'sku' => 'RW-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Setting::setValue('sales.delivery_order.default_requires_approval', false);
        Warehouse::factory()->create(['is_default' => true]);
    }

    private function draftQuotation(int $qty = 5): Quotation
    {
        $quotation = Quotation::create([
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

        QuotationLine::create([
            'quotation_id' => $quotation->id,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => $qty,
            'unit_price' => 100,
            'tax_rate' => 0,
            'discount_amount' => 0,
            'line_total' => $qty * 100,
        ]);

        return $quotation->fresh('lines');
    }

    public function test_delivery_order_is_order_linked_and_not_pre_delivered(): void
    {
        $quotation = $this->draftQuotation(5);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-delivery-order")
            ->assertCreated();

        $soId = $quotation->fresh()->sales_order_id;
        $this->assertNotNull($soId, 'Converting must release the quote to a Sales Order.');

        $do = DeliveryOrder::with('lines')->find($response->json('data.id'));
        $this->assertSame((int) $soId, (int) $do->sales_order_id, 'DO must be linked to the Sales Order.');
        $this->assertSame(5.0, (float) $do->lines->sum('quantity_delivered'), 'DO plans the full remaining quantity.');

        // Nothing is delivered on the ORDER until the DO is completed.
        $soLine = SalesOrderLine::where('sales_order_id', $soId)->first();
        $this->assertSame(0.0, (float) $soLine->quantity_delivered, 'DO creation must not pre-deliver the order.');
    }

    public function test_second_request_is_blocked_while_an_open_delivery_order_covers_the_remainder(): void
    {
        $quotation = $this->draftQuotation(5);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-delivery-order")
            ->assertCreated();

        // A second click must not ship the whole order again.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-delivery-order")
            ->assertStatus(422);

        $this->assertSame(
            1,
            DeliveryOrder::where('sales_order_id', $quotation->fresh()->sales_order_id)->count(),
            'Only one delivery order may exist for the covered quantity.',
        );
    }

    public function test_quote_to_delivery_order_to_invoice_by_delivered_quantity(): void
    {
        $quotation = $this->draftQuotation(5);

        $create = $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-delivery-order")
            ->assertCreated();

        $soId = $quotation->fresh()->sales_order_id;

        // Bill this line by DELIVERED quantity — so nothing is invoiceable until
        // the delivery actually completes.
        SalesOrderLine::where('sales_order_id', $soId)->update(['invoicing_policy' => 'delivered_qty']);

        // Complete the delivery → the order line's delivered quantity reconciles.
        $service = app(DeliveryOrderService::class);
        $do = DeliveryOrder::find($create->json('data.id'));
        $service->confirmDeliveryOrder($do);
        $service->completeDeliveryOrder($do->fresh());

        $soLine = SalesOrderLine::where('sales_order_id', $soId)->first();
        $this->assertSame(5.0, (float) $soLine->quantity_delivered, 'Completing the DO must reconcile delivered qty onto the order.');

        // Now a delivered-qty invoice can be raised and reflects the 5 delivered.
        $invoiceResponse = $this->actingAs($this->admin)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-invoice")
            ->assertCreated();

        $invoice = DB::table('invoices')->where('id', $invoiceResponse->json('data.id'))->first();
        $this->assertSame(500.0, (float) $invoice->total, 'Invoice must bill the 5 delivered units (5 × 100).');
    }
}
