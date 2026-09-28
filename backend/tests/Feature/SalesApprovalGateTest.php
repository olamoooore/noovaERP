<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Invoice;
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
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #180 — config-driven approval gate on Quotation / SalesOrder / Invoice
 * confirm paths.
 *
 * Verifies, via real HTTP requests (per the repo's engineering SOP §3):
 *
 *   1. No threshold setting → SO/Quotation confirm proceeds normally (200).
 *   2. Threshold set, total below → SO/Quotation confirm proceeds normally.
 *   3. Threshold set, total at/above → confirm returns 202 with an
 *      `ApprovalRequest` payload, and the document status stays `draft`.
 *   4. The `ApprovalRequest` row is persisted with the correct subject.
 *   5. Invoice threshold auto-promotes `requires_approval` when total >= threshold.
 */
class SalesApprovalGateTest extends TenantTestCase
{
    protected User $user;
    protected Customer $customer;
    protected Product $product;
    protected Warehouse $warehouse;
    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(function () {
            return Tenant::factory()->create(['id' => (string) Str::uuid()]);
        });
        $this->bindTenantContext($tenant->id);
        $this->tenantId = $tenant->id;

        $this->assertTenantSchemaReady([
            'customers', 'products', 'sales_orders', 'quotations',
            'approval_requests', 'settings',
        ]);

        $this->user = User::create([
            'name' => 'Sales Gate User',
            'email' => 'sales-gate-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
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
            'name' => 'Sales Gate Widget',
            'sku' => 'SG-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Sales Gate Warehouse',
            'code' => 'SGW-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function makeDraftSalesOrder(float $total): SalesOrder
    {
        $service = app(\Modules\Sales\Services\SalesOrderService::class);

        $lines = [];
        $units = (int) ceil($total / 100);
        $lines[] = [
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => $units,
            'unit_price' => 100,
            'tax_rate' => 0,
            'discount_amount' => 0,
        ];

        return $service->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => $lines,
        ]);
    }

    private function makeDraftQuotation(float $total): Quotation
    {
        $quotation = Quotation::create([
            'customer_id' => $this->customer->id,
            'quotation_number' => 'Q-'.uniqid(),
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'subtotal' => $total,
            'tax_amount' => 0,
            'total' => $total,
            'created_by' => $this->user->id,
        ]);

        QuotationLine::create([
            'quotation_id' => $quotation->id,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => (int) ceil($total / 100),
            'unit_price' => 100,
            'tax_rate' => 0,
            'discount_amount' => 0,
            'line_total' => $total,
        ]);

        return $quotation;
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. No threshold → confirm proceeds (200)
    // ─────────────────────────────────────────────────────────────────────

    public function test_sales_order_confirm_proceeds_when_no_threshold_is_set(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        $order = $this->makeDraftSalesOrder(500);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/sales/orders/{$order->id}/confirm");

        $response->assertOk();
        $response->assertJsonPath('message', 'Order confirmed');
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame(0, ApprovalRequest::count(), 'No approval request should be created when the gate is disabled.');
    }

    public function test_quotation_confirm_proceeds_when_no_threshold_is_set(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        $quotation = $this->makeDraftQuotation(500);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm");

        $response->assertOk();
        $response->assertJsonPath('message', 'Quotation confirmed successfully');
        $this->assertSame('confirmed', $quotation->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. Threshold set, total below → confirm proceeds
    // ─────────────────────────────────────────────────────────────────────

    public function test_sales_order_confirm_proceeds_when_total_below_threshold(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);

        $order = $this->makeDraftSalesOrder(500);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/sales/orders/{$order->id}/confirm");

        $response->assertOk();
        $response->assertJsonPath('message', 'Order confirmed');
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame(0, ApprovalRequest::count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. Threshold set, total at/above → confirm returns 202 + ApprovalRequest
    // ─────────────────────────────────────────────────────────────────────

    public function test_sales_order_confirm_returns_202_when_total_meets_threshold(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);

        $order = $this->makeDraftSalesOrder(2000);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/sales/orders/{$order->id}/confirm");

        $response->assertStatus(202);
        $response->assertJsonPath('message', 'Approval required');
        $response->assertJsonPath('requires_approval', true);
        $response->assertJsonPath('threshold', 1000);

        $this->assertSame(1, ApprovalRequest::count(), 'Exactly one ApprovalRequest must be created.');
        $approval = ApprovalRequest::first();
        $this->assertSame(SalesApprovalGate::REQUEST_TYPE_SALES_ORDER, $approval->request_type);
        $this->assertSame(SalesOrder::class, $approval->subject_type);
        $this->assertSame($order->id, $approval->subject_id);
        $this->assertSame('pending', $approval->status);
        $this->assertSame((float) $order->total, (float) $approval->amount);

        $this->assertSame('draft', $order->fresh()->status, 'Order must remain in draft while gated.');
    }

    public function test_quotation_confirm_returns_202_when_total_meets_threshold(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);

        $quotation = $this->makeDraftQuotation(2500);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm");

        $response->assertStatus(202);
        $response->assertJsonPath('message', 'Approval required');
        $response->assertJsonPath('requires_approval', true);

        $this->assertSame(1, ApprovalRequest::count());
        $approval = ApprovalRequest::first();
        $this->assertSame(SalesApprovalGate::REQUEST_TYPE_QUOTATION, $approval->request_type);
        $this->assertSame(Quotation::class, $approval->subject_type);
        $this->assertSame($quotation->id, $approval->subject_id);
        $this->assertSame('draft', $quotation->fresh()->status);
        $this->assertNull($quotation->fresh()->sales_order_id, 'No SalesOrder may be created while gated.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. Threshold at exactly the boundary fires the gate
    // ─────────────────────────────────────────────────────────────────────

    public function test_threshold_boundary_is_inclusive(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);

        $order = $this->makeDraftSalesOrder(1000); // exactly at threshold

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/sales/orders/{$order->id}/confirm");

        $response->assertStatus(202);
        $this->assertSame(1, ApprovalRequest::count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. Invoice threshold auto-promotion
    // ─────────────────────────────────────────────────────────────────────

    public function test_invoice_update_auto_promotes_requires_approval_when_total_meets_threshold(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 500);

        $invoice = Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'USD',
            'status' => 'draft',
            'subtotal' => 600,
            'tax_amount' => 0,
            'total' => 600,
            'requires_approval' => false,
            'created_by' => $this->user->id,
        ]);

        // A line item is required so `calculateTotals()` preserves the total;
        // otherwise the recalc zeros it out and the gate wouldn't fire.
        \App\Models\InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => 6,
            'unit_price' => 100,
            'tax_rate' => 0,
            'discount_amount' => 0,
            'line_total' => 600,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'notes' => 'Threshold check',
            ]);

        $response->assertOk();
        $this->assertTrue(
            (bool) $invoice->fresh()->requires_approval,
            'Invoice must auto-promote to requires_approval when total >= threshold.'
        );
    }

    public function test_invoice_update_does_not_promote_when_total_below_threshold(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);

        $invoice = Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'USD',
            'status' => 'draft',
            'subtotal' => 100,
            'tax_amount' => 0,
            'total' => 100,
            'requires_approval' => false,
            'created_by' => $this->user->id,
        ]);

        \App\Models\InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
            'discount_amount' => 0,
            'line_total' => 100,
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'notes' => 'Below threshold',
            ])
            ->assertOk();

        $this->assertFalse(
            (bool) $invoice->fresh()->requires_approval,
            'Invoice must NOT be promoted when total is below the threshold.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. The gate applies to every downstream route, not just confirm
    // ─────────────────────────────────────────────────────────────────────

    public function test_quotation_invoice_conversion_requires_approval_when_threshold_is_met(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        $quotation = $this->makeDraftQuotation(2500);
        $this->actingAs($this->user)->postJson("/api/v1/quotations/{$quotation->id}/confirm")->assertOk();
        $quotation->refresh();
        $this->assertNotNull($quotation->sales_order_id);

        // Threshold enabled after the quotation already exists.
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-invoice");

        $response->assertStatus(202);
        $response->assertJsonPath('requires_approval', true);

        $approval = ApprovalRequest::firstOrFail();
        $this->assertSame(SalesApprovalGate::REQUEST_TYPE_QUOTATION, $approval->request_type);
        $this->assertSame(Quotation::class, $approval->subject_type);
        $this->assertSame($quotation->id, $approval->subject_id);

        $this->assertSame(
            0,
            Invoice::where('sales_order_id', $quotation->sales_order_id)->count(),
            'No invoice may be created while the approval is pending.'
        );
    }

    public function test_quotation_delivery_conversion_requires_approval_when_threshold_is_met(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        $quotation = $this->makeDraftQuotation(2500);
        $this->actingAs($this->user)->postJson("/api/v1/quotations/{$quotation->id}/confirm")->assertOk();

        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-delivery-order");

        $response->assertStatus(202);
        $response->assertJsonPath('requires_approval', true);

        $this->assertSame(1, ApprovalRequest::count());
        $this->assertSame(
            0,
            \Modules\Sales\Models\DeliveryOrder::where('quotation_id', $quotation->id)->count(),
            'No delivery order may be created while the approval is pending.'
        );
    }

    public function test_quotation_conversion_proceeds_once_the_request_is_approved(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        $quotation = $this->makeDraftQuotation(2500);
        $this->actingAs($this->user)->postJson("/api/v1/quotations/{$quotation->id}/confirm")->assertOk();
        $quotation->refresh();
        $this->assertNotNull($quotation->sales_order_id);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);
        $this->actingAs($this->user)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-invoice")
            ->assertStatus(202);

        ApprovalRequest::firstOrFail()->update([
            'status' => 'approved',
            'reviewed_by' => $this->user->id,
            'reviewed_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-invoice")
            ->assertStatus(201);

        $this->assertGreaterThan(0, Invoice::where('sales_order_id', $quotation->sales_order_id)->count());
        $this->assertSame(1, ApprovalRequest::count(), 'The approved request is reused, never duplicated.');
    }

    public function test_quotation_confirm_resumes_after_approval(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);
        $quotation = $this->makeDraftQuotation(2500);

        $this->actingAs($this->user)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertStatus(202);
        $this->assertSame('draft', $quotation->fresh()->status);

        ApprovalRequest::firstOrFail()->update([
            'status' => 'approved',
            'reviewed_by' => $this->user->id,
            'reviewed_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $quotation->refresh();
        $this->assertSame('confirmed', $quotation->status);
        $this->assertNotNull($quotation->sales_order_id);
        $this->assertSame(1, ApprovalRequest::count(), 'Re-running confirm after approval must not duplicate the request.');
    }
}
