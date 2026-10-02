<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InvoiceApprovalService;
use App\Services\SalesApprovalGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #647 — the invoice approval threshold was only applied inside
 * InvoiceController::update(). Invoices created above the tenant threshold
 * (direct create, SO→invoice, imports, channel paths) were never flagged and
 * no approval request was raised, so an over-threshold invoice could be posted
 * without any approval.
 *
 * These are router-level tests (per the repo SOP §3): invoices are created
 * through the real HTTP routes and the persisted Invoice + ApprovalRequest
 * rows are asserted afterwards.
 */
class InvoiceApprovalThresholdOnCreateTest extends TenantTestCase
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
            'customers', 'products', 'invoices', 'invoice_lines',
            'approval_requests', 'settings',
        ]);

        $this->user = User::create([
            'name' => 'Threshold Tester',
            'email' => 'threshold-'.uniqid().'@test.local',
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
            'name' => 'Threshold Widget',
            'sku' => 'THR-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        $this->warehouse = Warehouse::factory()->create(['is_default' => true]);
    }

    private function makeConfirmedSalesOrder(float $total = 600): \Modules\Sales\Models\SalesOrder
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        $order = app(\Modules\Sales\Services\SalesOrderService::class)->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => (int) ceil($total / 100),
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/sales/orders/{$order->id}/confirm")
            ->assertOk();

        return $order->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Direct create via POST /api/v1/invoices
    // ─────────────────────────────────────────────────────────────────────

    public function test_direct_invoice_above_threshold_requires_approval_and_submits_a_request(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 500);

        $response = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 6,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        $response->assertCreated();

        $invoice = Invoice::findOrFail($response->json('id'));

        $this->assertTrue(
            (bool) $invoice->requires_approval,
            'An invoice created above the tenant threshold must have requires_approval=true.'
        );
        $this->assertSame(600.0, (float) $invoice->total);
        $this->assertSame(
            Invoice::APPROVAL_PENDING,
            $invoice->approval_status,
            'The create flow must submit the invoice for approval when the threshold is met.'
        );

        $approval = ApprovalRequest::query()
            ->where('subject_type', Invoice::class)
            ->where('subject_id', $invoice->id)
            ->firstOrFail();

        $this->assertSame(InvoiceApprovalService::REQUEST_TYPE, $approval->request_type);
        $this->assertSame('pending', $approval->status);
        $this->assertSame(600.0, (float) $approval->amount);
    }

    // ─────────────────────────────────────────────────────────────────────
    // SO → invoice via POST /api/v1/sales/orders/{id}/invoice
    // ─────────────────────────────────────────────────────────────────────

    public function test_sales_order_invoice_above_threshold_requires_approval_and_submits_a_request(): void
    {
        $order = $this->makeConfirmedSalesOrder(600);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, 500);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/sales/orders/{$order->id}/invoice");

        $response->assertCreated();

        $invoice = Invoice::findOrFail($response->json('invoice.id'));

        $this->assertSame(600.0, (float) $invoice->total);
        $this->assertTrue(
            (bool) $invoice->requires_approval,
            'An SO→invoice above the tenant threshold must have requires_approval=true.'
        );
        $this->assertSame(
            Invoice::APPROVAL_PENDING,
            $invoice->approval_status,
            'The SO→invoice flow must submit the invoice for approval when the threshold is met.'
        );

        $approval = ApprovalRequest::query()
            ->where('subject_type', Invoice::class)
            ->where('subject_id', $invoice->id)
            ->firstOrFail();

        $this->assertSame(InvoiceApprovalService::REQUEST_TYPE, $approval->request_type);
        $this->assertSame('pending', $approval->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Below the threshold must stay ungated
    // ─────────────────────────────────────────────────────────────────────

    public function test_direct_invoice_below_threshold_stays_ungated(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);

        $response = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 6,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);

        $response->assertCreated();

        $invoice = Invoice::findOrFail($response->json('id'));

        $this->assertFalse(
            (bool) $invoice->requires_approval,
            'An invoice below the tenant threshold must not be flagged for approval.'
        );
        $this->assertSame(0, ApprovalRequest::query()
            ->where('subject_type', Invoice::class)
            ->where('subject_id', $invoice->id)
            ->count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Every total-changing update: edit grows the invoice past the threshold
    // ─────────────────────────────────────────────────────────────────────

    public function test_invoice_edited_above_threshold_is_promoted(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 500);

        $invoice = Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'USD',
            'base_currency_code' => 'USD',
            'exchange_rate' => 1,
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
                'lines' => [[
                    'line_type' => 'product',
                    'product_id' => $this->product->id,
                    'description' => $this->product->name,
                    'quantity' => 9,
                    'unit_price' => 100,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ]],
            ])
            ->assertOk();

        $this->assertSame(900.0, (float) $invoice->fresh()->total);
        $this->assertTrue(
            (bool) $invoice->fresh()->requires_approval,
            'An edit that raises the total above the threshold must promote requires_approval.'
        );
    }
}
