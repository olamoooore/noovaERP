<?php

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
use Tests\TenantTestCase;

/**
 * Quotation confirm/release + the approval defaults of the documents it spawns.
 *
 * Confirming a quotation only creates the linked SalesOrder — no ledger entry —
 * so the preparer role may release it (`sales.write`). Ledger-affecting release
 * actions (invoice post/send, delivery-order confirm) remain `accounting.post`.
 *
 * The invoice and delivery order created FROM a quotation must honour the
 * tenant's approval defaults (sales.invoice.default_requires_approval,
 * sales.delivery_order.default_requires_approval) regardless of which surface
 * triggered the creation — previously server-side DO creation skipped the DO
 * default entirely because only the create form sent the flag.
 *
 * Exercises the real router/middleware/controller chain via postJson.
 */
class QuotationConfirmPermissionTest extends TenantTestCase
{
    protected User $sales;

    protected User $manager;

    protected User $accountant;

    protected Customer $customer;

    protected Product $product;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(function () {
            return Tenant::factory()->create(['id' => (string) Str::uuid()]);
        });

        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->sales = $this->makeUser('sales', UserRole::SALES);
        $this->manager = $this->makeUser('manager', UserRole::MANAGER);
        $this->accountant = $this->makeUser('accountant', UserRole::ACCOUNTANT);

        $this->customer = Customer::factory()->create([
            'payment_terms' => 30,
            'credit_limit' => 100000,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Quotation RBAC Widget',
            'sku' => 'QRBAC-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        // No approval threshold: confirm proceeds straight through. The
        // per-document approval defaults are set inside the tests that need
        // them so the default flow stays un-gated.
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        Warehouse::factory()->create(['is_default' => true]);
    }

    private function makeUser(string $accessRole, UserRole $spatieRole): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole->spatieName());

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $accessRole,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function makeDraftQuotation(float $total = 500): Quotation
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
            'created_by' => $this->accountant->id,
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

    public function test_sales_can_confirm_a_quotation(): void
    {
        $quotation = $this->makeDraftQuotation();

        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $quotation->refresh();
        $this->assertSame('confirmed', $quotation->status);
        $this->assertNotNull($quotation->sales_order_id);
    }

    public function test_manager_can_confirm_a_quotation(): void
    {
        $quotation = $this->makeDraftQuotation();

        $this->actingAs($this->manager)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $quotation->refresh();
        $this->assertSame('confirmed', $quotation->status);
        $this->assertNotNull($quotation->sales_order_id);
    }

    public function test_cashier_cannot_confirm_a_quotation(): void
    {
        $quotation = $this->makeDraftQuotation();
        $cashier = $this->makeUser('cashier', UserRole::CASHIER);

        $this->actingAs($cashier)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertForbidden();

        $this->assertSame('draft', $quotation->fresh()->status);
    }

    public function test_accountant_can_confirm_a_quotation(): void
    {
        $quotation = $this->makeDraftQuotation();

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $quotation->refresh();
        $this->assertSame('confirmed', $quotation->status);
        $this->assertNotNull($quotation->sales_order_id);
    }

    public function test_documents_created_from_a_quotation_honour_tenant_approval_defaults(): void
    {
        Setting::setValue('sales.invoice.default_requires_approval', true);
        Setting::setValue('sales.delivery_order.default_requires_approval', true);

        $quotation = $this->makeDraftQuotation();

        // The quote itself never needs approval — the preparer releases it.
        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        // Invoice from the quote: created with the tenant approval flag set.
        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-invoice")
            ->assertCreated();

        $invoice = DB::table('invoices')->where('quotation_id', $quotation->id)->latest('id')->first();
        $this->assertNotNull($invoice, 'Invoice should be created from the quotation');
        $this->assertTrue((bool) $invoice->requires_approval, 'Invoice must carry the tenant approval default');
        $this->assertSame('draft', $invoice->status, 'Approval-gated invoices must not post straight away');
        $this->assertSame('pending', $invoice->approval_status, 'Required invoices must be submitted to the approval channel on creation');
        $this->assertTrue(
            DB::table('approval_requests')
                ->where('subject_type', \App\Models\Invoice::class)
                ->where('subject_id', $invoice->id)
                ->where('status', 'pending')
                ->exists(),
            'A pending approval request must exist for the invoice'
        );

        // Delivery order from the quote: the tenant DO approval default applies
        // even though this creation path never sent a requires_approval flag.
        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-delivery-order")
            ->assertCreated();

        $deliveryOrder = DB::table('delivery_orders')->where('quotation_id', $quotation->id)->latest('id')->first();
        $this->assertNotNull($deliveryOrder, 'Delivery order should be created from the quotation');
        $this->assertTrue((bool) $deliveryOrder->requires_approval, 'DO must carry the tenant approval default');
        $this->assertSame('pending', $deliveryOrder->approval_status, 'DO must start approval-pending');
        $this->assertTrue(
            DB::table('approval_requests')
                ->where('subject_type', \Modules\Sales\Models\DeliveryOrder::class)
                ->where('subject_id', $deliveryOrder->id)
                ->where('status', 'pending')
                ->exists(),
            'The delivery order must be submitted to the approval channel'
        );
    }

    public function test_delivery_order_approval_decided_in_the_inbox_updates_the_document(): void
    {
        $reviewer = $this->makeUser('manager', UserRole::MANAGER);
        Setting::setValue('sales.invoice.reviewer_user_ids', [$reviewer->id]);
        Setting::setValue('sales.invoice.reviewer_roles', []);
        Setting::setValue('sales.delivery_order.default_requires_approval', true);

        $quotation = $this->makeDraftQuotation();

        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-delivery-order")
            ->assertCreated();

        $deliveryOrder = DB::table('delivery_orders')->where('quotation_id', $quotation->id)->latest('id')->first();
        $approvalRequest = DB::table('approval_requests')
            ->where('subject_type', \Modules\Sales\Models\DeliveryOrder::class)
            ->where('subject_id', $deliveryOrder->id)
            ->where('status', 'pending')
            ->first();

        $this->assertNotNull($approvalRequest, 'DO approval request expected');

        // The configured reviewer decides from the approvals channel.
        $this->actingAs($reviewer)
            ->postJson("/api/v1/approvals/{$approvalRequest->id}/approve", ['notes' => 'Approved in test'])
            ->assertOk();

        $this->assertSame('approved', DB::table('delivery_orders')->where('id', $deliveryOrder->id)->value('approval_status'));
        $this->assertSame('approved', DB::table('approval_requests')->where('id', $approvalRequest->id)->value('status'));
    }

    public function test_request_number_generation_ignores_malformed_history(): void
    {
        // Simulate the historical float-overflow numbers that used to poison
        // the sequence (they sorted to the top and made every new request
        // collide with one existing number).
        \App\Models\ApprovalRequest::create([
            'request_type' => 'invoice_approval',
            'subject_type' => \App\Models\Invoice::class,
            'subject_id' => 123456,
            'request_number' => 'APR'.now()->format('Ymd').'9.2233720368548E+18',
            'amount' => 1,
            'reason' => 'corrupt history',
            'requested_by' => $this->sales->id,
            'requested_at' => now(),
            'status' => 'approved',
        ]);

        $generated = \App\Models\ApprovalRequest::generateRequestNumber();

        $this->assertMatchesRegularExpression('/^APR\d{8}\d{6}$/', $generated);
        $this->assertFalse(
            DB::table('approval_requests')->where('request_number', $generated)->exists(),
            'Generated number must not collide with existing rows'
        );
    }
}
