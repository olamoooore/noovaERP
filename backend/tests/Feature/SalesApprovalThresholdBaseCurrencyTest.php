<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\SalesApprovalRule;
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
 * Issue #649 — the sales approval gate used ONE tenant-wide amount compared
 * directly against the document-currency total. A USD 10,000 order was judged
 * the same as MYR 10,000, and there was no way to set a threshold per document
 * type or to express conditions (discount %, margin, credit exposure) — only
 * an amount.
 *
 * These are router-level tests (per the repo SOP §3): documents are created
 * through the real services and the gate is exercised through the real HTTP
 * routes, then the persisted ApprovalRequest / requires_approval state is
 * asserted.
 */
class SalesApprovalThresholdBaseCurrencyTest extends TenantTestCase
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
            'name' => 'FX Threshold Tester',
            'email' => 'fx-threshold-'.uniqid().'@test.local',
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
            'credit_limit' => 1000000,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'FX Threshold Widget',
            'sku' => 'FXT-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'FX Threshold Warehouse',
            'code' => 'FXTW-'.uniqid(),
            'is_active' => true,
        ]);
    }

    /**
     * A USD order at 4.7 MYR/USD. 100 units × USD 100 = USD 10,000
     * (MYR 47,000 in base currency).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeDraftSalesOrder(array $overrides = [], int $units = 100, float $unitPrice = 100.0): SalesOrder
    {
        return app(\Modules\Sales\Services\SalesOrderService::class)->createOrder(array_merge([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'currency_code' => 'USD',
            'exchange_rate' => 4.7,
            'base_currency_code' => 'MYR',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => $units,
                'unit_price' => $unitPrice,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ], $overrides));
    }

    private function confirmSalesOrder(SalesOrder $order)
    {
        return $this->actingAs($this->user)
            ->postJson("/api/v1/sales/orders/{$order->id}/confirm");
    }

    // ─────────────────────────────────────────────────────────────────────
    // Base-currency comparison
    // ─────────────────────────────────────────────────────────────────────

    public function test_sales_order_is_compared_in_base_currency_not_document_currency(): void
    {
        // MYR 20,000. The USD 10,000 face amount is below it; the MYR 47,000
        // base-currency equivalent is above it.
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 20000);

        $order = $this->makeDraftSalesOrder();

        $this->assertSame(10000.0, (float) $order->total, 'The order is denominated in USD.');
        $this->assertSame('MYR', $order->base_currency_code);
        $this->assertSame(4.7, (float) $order->exchange_rate);

        $this->confirmSalesOrder($order)->assertStatus(202);

        $approval = ApprovalRequest::query()
            ->where('subject_type', SalesOrder::class)
            ->where('subject_id', $order->id)
            ->firstOrFail();

        $this->assertSame(SalesApprovalGate::REQUEST_TYPE_SALES_ORDER, $approval->request_type);
        $this->assertSame('pending', $approval->status);
        $this->assertSame('draft', $order->fresh()->status, 'The order must stay draft while gated.');
    }

    public function test_sales_order_below_threshold_after_conversion_stays_ungated(): void
    {
        // MYR 50,000 > MYR 47,000 converted total.
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 50000);

        $order = $this->makeDraftSalesOrder();

        $this->confirmSalesOrder($order)->assertOk();
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame(0, ApprovalRequest::count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Per-document-type thresholds
    // ─────────────────────────────────────────────────────────────────────

    public function test_per_type_threshold_overrides_the_tenant_wide_setting(): void
    {
        // The tenant-wide setting is far above the order's converted total;
        // the sales-order override is below it. The override must win.
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000000);
        Setting::setValue('sales.sales_order.approval_threshold_amount', 20000);

        $order = $this->makeDraftSalesOrder();

        $this->confirmSalesOrder($order)->assertStatus(202);
        $this->assertSame(1, ApprovalRequest::count());
        $this->assertSame('draft', $order->fresh()->status);
    }

    public function test_per_type_setting_absent_falls_back_to_the_tenant_wide_threshold(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 500);
        Setting::setValue('sales.sales_order.approval_threshold_amount', null);

        // Base-currency document (USD/USD at rate 1): 10 × 100 = 1000.
        $order = $this->makeDraftSalesOrder([
            'currency_code' => 'USD',
            'base_currency_code' => 'USD',
            'exchange_rate' => 1,
        ], units: 10);

        $this->confirmSalesOrder($order)->assertStatus(202);
        $this->assertSame(1, ApprovalRequest::count());
    }

    public function test_threshold_disabled_without_per_type_override_stays_ungated(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Setting::setValue('sales.sales_order.approval_threshold_amount', null);

        $order = $this->makeDraftSalesOrder();

        $this->confirmSalesOrder($order)->assertOk();
        $this->assertSame(0, ApprovalRequest::count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Invoice per-type threshold through the edit (total change) route
    // ─────────────────────────────────────────────────────────────────────

    public function test_invoice_per_type_threshold_compares_the_converted_total(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Setting::setValue('sales.invoice.approval_threshold_amount', 4000);

        // USD 1,000 at 4.7 = MYR 4,700 base.
        $invoice = Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'USD',
            'base_currency_code' => 'MYR',
            'exchange_rate' => 4.7,
            'status' => 'draft',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'total' => 1000,
            'requires_approval' => false,
            'created_by' => $this->user->id,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => 10,
            'unit_price' => 100,
            'tax_rate' => 0,
            'discount_amount' => 0,
            'line_total' => 1000,
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/invoices/{$invoice->id}", ['notes' => 'FX threshold check'])
            ->assertOk();

        $this->assertTrue(
            (bool) $invoice->fresh()->requires_approval,
            'The invoice must be promoted when its base-currency total meets the per-type threshold.'
        );
    }

    public function test_invoice_below_converted_per_type_threshold_stays_ungated(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Setting::setValue('sales.invoice.approval_threshold_amount', 5000);

        $invoice = Invoice::create([
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_code' => 'USD',
            'base_currency_code' => 'MYR',
            'exchange_rate' => 4.7,
            'status' => 'draft',
            'subtotal' => 1000,
            'tax_amount' => 0,
            'total' => 1000,
            'requires_approval' => false,
            'created_by' => $this->user->id,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'description' => $this->product->name,
            'quantity' => 10,
            'unit_price' => 100,
            'tax_rate' => 0,
            'discount_amount' => 0,
            'line_total' => 1000,
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/invoices/{$invoice->id}", ['notes' => 'Below FX threshold'])
            ->assertOk();

        $this->assertFalse((bool) $invoice->fresh()->requires_approval);
    }

    // ─────────────────────────────────────────────────────────────────────
    // SalesApprovalRule — amount condition
    // ─────────────────────────────────────────────────────────────────────

    public function test_active_amount_rule_requires_approval_without_a_threshold(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        SalesApprovalRule::create([
            'document_type' => SalesApprovalRule::DOCUMENT_TYPE_SALES_ORDER,
            'condition_kind' => SalesApprovalRule::CONDITION_AMOUNT,
            'operator' => '>=',
            'value' => 20000,
            'active' => true,
        ]);

        // USD 10,000 → MYR 47,000 base, above the rule value.
        $order = $this->makeDraftSalesOrder();

        $this->confirmSalesOrder($order)->assertStatus(202);
        $this->assertSame(1, ApprovalRequest::count());
    }

    public function test_inactive_rule_does_not_require_approval(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        SalesApprovalRule::create([
            'document_type' => SalesApprovalRule::DOCUMENT_TYPE_SALES_ORDER,
            'condition_kind' => SalesApprovalRule::CONDITION_AMOUNT,
            'operator' => '>=',
            'value' => 20000,
            'active' => false,
        ]);

        $order = $this->makeDraftSalesOrder();

        $this->confirmSalesOrder($order)->assertOk();
        $this->assertSame(0, ApprovalRequest::count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // SalesApprovalRule — discount % condition
    // ─────────────────────────────────────────────────────────────────────

    public function test_discount_percentage_rule_requires_approval(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        SalesApprovalRule::create([
            'document_type' => SalesApprovalRule::DOCUMENT_TYPE_SALES_ORDER,
            'condition_kind' => SalesApprovalRule::CONDITION_DISCOUNT_PCT,
            'operator' => '>=',
            'value' => 15,
            'active' => true,
        ]);

        // 10 × 100 = 1,000 subtotal with a 200 header discount = 20%.
        $order = $this->makeDraftSalesOrder(['discount_amount' => 200], units: 10);

        $this->assertSame(800.0, (float) $order->fresh()->total);

        $this->confirmSalesOrder($order)->assertStatus(202);
        $this->assertSame(1, ApprovalRequest::count());
    }

    public function test_discount_rule_below_the_configured_percentage_stays_ungated(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        SalesApprovalRule::create([
            'document_type' => SalesApprovalRule::DOCUMENT_TYPE_SALES_ORDER,
            'condition_kind' => SalesApprovalRule::CONDITION_DISCOUNT_PCT,
            'operator' => '>=',
            'value' => 25,
            'active' => true,
        ]);

        // 20% discount is below the 25% rule.
        $order = $this->makeDraftSalesOrder(['discount_amount' => 200], units: 10);

        $this->confirmSalesOrder($order)->assertOk();
        $this->assertSame(0, ApprovalRequest::count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // SalesApprovalRule — margin condition
    // ─────────────────────────────────────────────────────────────────────

    public function test_margin_rule_is_evaluated_from_product_costs(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        SalesApprovalRule::create([
            'document_type' => SalesApprovalRule::DOCUMENT_TYPE_SALES_ORDER,
            'condition_kind' => SalesApprovalRule::CONDITION_MARGIN,
            'operator' => '<',
            'value' => 50,
            'active' => true,
        ]);

        // Product cost 25 against a 100 price = 75% margin, above the rule.
        $order = $this->makeDraftSalesOrder(units: 10);

        $this->confirmSalesOrder($order)->assertOk();
        $this->assertSame(0, ApprovalRequest::count());
    }

    public function test_margin_rule_fails_safe_when_product_cost_is_unavailable(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        SalesApprovalRule::create([
            'document_type' => SalesApprovalRule::DOCUMENT_TYPE_SALES_ORDER,
            'condition_kind' => SalesApprovalRule::CONDITION_MARGIN,
            'operator' => '<',
            'value' => 50,
            'active' => true,
        ]);

        $order = $this->makeDraftSalesOrder(units: 10);

        // The product behind the line is removed after the order was created —
        // its cost can no longer be resolved, so the margin is unknowable.
        $this->product->delete();

        // The gate must fail safe and require approval rather than silently
        // letting the document through.
        $this->confirmSalesOrder($order)->assertStatus(202);
        $this->assertSame(1, ApprovalRequest::count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // SalesApprovalRule — credit exposure condition
    // ─────────────────────────────────────────────────────────────────────

    public function test_credit_exposure_rule_requires_approval(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        SalesApprovalRule::create([
            'document_type' => SalesApprovalRule::DOCUMENT_TYPE_SALES_ORDER,
            'condition_kind' => SalesApprovalRule::CONDITION_CREDIT_EXPOSURE,
            'operator' => '>=',
            'value' => 20000,
            'active' => true,
        ]);

        // No outstanding AR; the order adds MYR 47,000 of prospective exposure.
        $order = $this->makeDraftSalesOrder();

        $this->confirmSalesOrder($order)->assertStatus(202);
        $this->assertSame(1, ApprovalRequest::count());
    }

    public function test_credit_exposure_rule_below_the_configured_value_stays_ungated(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        SalesApprovalRule::create([
            'document_type' => SalesApprovalRule::DOCUMENT_TYPE_SALES_ORDER,
            'condition_kind' => SalesApprovalRule::CONDITION_CREDIT_EXPOSURE,
            'operator' => '>=',
            'value' => 1000000,
            'active' => true,
        ]);

        // MYR 47,000 prospective exposure is below the 1,000,000 rule.
        $order = $this->makeDraftSalesOrder();

        $this->confirmSalesOrder($order)->assertOk();
        $this->assertSame(0, ApprovalRequest::count());
    }
}
