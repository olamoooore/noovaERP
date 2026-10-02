<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ApprovalService;
use App\Services\InvoiceApprovalService;
use App\Services\SalesApprovalGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #645 — sales approval requests were unapprovable.
 *
 * SalesApprovalGate creates quotation / sales-order / invoice-threshold /
 * delivery-order ApprovalRequests, but ApprovalService::userCanApprove() only
 * knew the POS entitlement types plus invoice/DO, so every configured reviewer
 * received a 403 and the document could never proceed.
 *
 * These are router-level tests: the request is created through the real
 * controller route, then approved through POST /api/v1/approvals/{id}/approve
 * (auth:sanctum → ApprovalController → ApprovalService), and the gated document
 * action is re-run through its own route.
 */
class SalesApprovalReviewerResolutionTest extends TenantTestCase
{
    protected User $sales;

    protected User $reviewer;

    protected User $viewer;

    protected User $owner;

    protected User $admin;

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

        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->sales = $this->makeUser('sales', UserRole::SALES);
        $this->reviewer = $this->makeUser('manager', UserRole::MANAGER);
        $this->viewer = $this->makeUser('viewer', UserRole::VIEWER);
        $this->owner = $this->makeUser('owner', UserRole::OWNER);
        $this->admin = $this->makeUser('admin', UserRole::ADMIN);

        $this->customer = Customer::factory()->create([
            'payment_terms' => 30,
            'credit_limit' => 100000,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Approval Reviewer Widget',
            'sku' => 'APR-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        $this->warehouse = Warehouse::factory()->create(['is_default' => true]);

        // Baseline: gate off and no reviewer pool. Each test configures only
        // what it exercises.
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Setting::setValue('sales.invoice.reviewer_user_ids', []);
        Setting::setValue('sales.invoice.reviewer_roles', []);
        Setting::setValue('sales.quotation.reviewer_user_ids', []);
        Setting::setValue('sales.quotation.reviewer_roles', []);
        Setting::setValue('sales.sales_order.reviewer_user_ids', []);
        Setting::setValue('sales.sales_order.reviewer_roles', []);
        Setting::setValue('sales.delivery_order.reviewer_user_ids', []);
        Setting::setValue('sales.delivery_order.reviewer_roles', []);
        Setting::setValue('sales.invoice_threshold.reviewer_user_ids', []);
        Setting::setValue('sales.invoice_threshold.reviewer_roles', []);
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

    private function makeDraftQuotation(float $total = 2500): Quotation
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
            'created_by' => $this->sales->id,
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

    private function makeDraftSalesOrder(float $total = 2500): SalesOrder
    {
        $service = app(\Modules\Sales\Services\SalesOrderService::class);

        return $service->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->sales->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => (int) ceil($total / 100),
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Quotation: configured reviewer approves → confirm resumes
    // ─────────────────────────────────────────────────────────────────────

    public function test_quotation_over_threshold_configured_reviewer_can_approve_and_confirm_resumes(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);
        Setting::setValue('sales.quotation.reviewer_user_ids', [$this->reviewer->id]);
        Setting::setValue('sales.quotation.reviewer_roles', []);

        $quotation = $this->makeDraftQuotation(2500);

        // 1. Confirm over the threshold → 202 + pending quotation_approval.
        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertStatus(202)
            ->assertJsonPath('requires_approval', true);

        $approval = ApprovalRequest::query()
            ->where('request_type', SalesApprovalGate::REQUEST_TYPE_QUOTATION)
            ->where('subject_id', $quotation->id)
            ->firstOrFail();

        $this->assertSame('pending', $approval->status);

        // 2. A user outside the configured reviewer list is rejected.
        $this->actingAs($this->viewer)
            ->postJson("/api/v1/approvals/{$approval->id}/approve", ['notes' => 'not my call'])
            ->assertForbidden();

        $this->assertSame('pending', $approval->fresh()->status, 'Non-reviewer must not change the request.');

        // 3. The configured reviewer approves.
        $this->actingAs($this->reviewer)
            ->postJson("/api/v1/approvals/{$approval->id}/approve", ['notes' => 'Approved in test'])
            ->assertOk();

        $this->assertSame('approved', $approval->fresh()->status);

        // 4. Re-running confirm now proceeds and creates the sales order.
        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $quotation->refresh();
        $this->assertSame('confirmed', $quotation->status);
        $this->assertNotNull($quotation->sales_order_id);
        $this->assertSame(1, ApprovalRequest::count(), 'The approved request must be reused, never duplicated.');
    }

    public function test_quotation_reviewers_fall_back_to_the_existing_invoice_pool(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);
        Setting::setValue('sales.invoice.reviewer_user_ids', [$this->reviewer->id]);
        Setting::setValue('sales.invoice.reviewer_roles', []);

        $quotation = $this->makeDraftQuotation(2500);

        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertStatus(202);

        $approval = ApprovalRequest::query()
            ->where('request_type', SalesApprovalGate::REQUEST_TYPE_QUOTATION)
            ->where('subject_id', $quotation->id)
            ->firstOrFail();

        // Exporting tenants configured the invoice reviewer pool before
        // per-type lists existed; it must keep covering quotation approvals.
        $this->actingAs($this->reviewer)
            ->postJson("/api/v1/approvals/{$approval->id}/approve", ['notes' => 'legacy pool'])
            ->assertOk();

        $this->assertSame('approved', $approval->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sales order: own reviewer configuration
    // ─────────────────────────────────────────────────────────────────────

    public function test_sales_order_over_threshold_uses_its_own_reviewer_configuration(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);
        Setting::setValue('sales.sales_order.reviewer_user_ids', [$this->reviewer->id]);
        Setting::setValue('sales.sales_order.reviewer_roles', []);

        $order = $this->makeDraftSalesOrder(2500);

        $this->actingAs($this->sales)
            ->postJson("/api/v1/sales/orders/{$order->id}/confirm")
            ->assertStatus(202)
            ->assertJsonPath('requires_approval', true);

        $approval = ApprovalRequest::query()
            ->where('request_type', SalesApprovalGate::REQUEST_TYPE_SALES_ORDER)
            ->where('subject_id', $order->id)
            ->firstOrFail();

        $this->actingAs($this->reviewer)
            ->postJson("/api/v1/approvals/{$approval->id}/approve")
            ->assertOk();

        $this->actingAs($this->sales)
            ->postJson("/api/v1/sales/orders/{$order->id}/confirm")
            ->assertOk();

        $this->assertSame('confirmed', $order->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Separation of duties
    // ─────────────────────────────────────────────────────────────────────

    public function test_separation_of_duties_still_blocks_self_approval(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);
        // Even with the requester explicitly configured as a reviewer, they
        // may not approve their own request.
        Setting::setValue('sales.quotation.reviewer_user_ids', [$this->sales->id]);
        Setting::setValue('sales.quotation.reviewer_roles', []);

        $quotation = $this->makeDraftQuotation(2500);

        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertStatus(202);

        $approval = ApprovalRequest::query()
            ->where('request_type', SalesApprovalGate::REQUEST_TYPE_QUOTATION)
            ->where('subject_id', $quotation->id)
            ->firstOrFail();

        $this->actingAs($this->sales)
            ->postJson("/api/v1/approvals/{$approval->id}/approve")
            ->assertForbidden();

        $this->assertSame('pending', $approval->fresh()->status);
        $this->assertSame('draft', $quotation->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Owner / Admin implicit bypass
    // ─────────────────────────────────────────────────────────────────────

    public function test_owner_and_admin_can_always_approve_sales_requests(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);
        // Deliberately no configured reviewers: the super roles still decide.

        $first = $this->makeDraftQuotation(2500);
        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$first->id}/confirm")
            ->assertStatus(202);

        $firstApproval = ApprovalRequest::query()
            ->where('request_type', SalesApprovalGate::REQUEST_TYPE_QUOTATION)
            ->where('subject_id', $first->id)
            ->firstOrFail();

        $this->actingAs($this->owner)
            ->postJson("/api/v1/approvals/{$firstApproval->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $firstApproval->fresh()->status);

        $second = $this->makeDraftQuotation(2500);
        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$second->id}/confirm")
            ->assertStatus(202);

        $secondApproval = ApprovalRequest::query()
            ->where('request_type', SalesApprovalGate::REQUEST_TYPE_QUOTATION)
            ->where('subject_id', $second->id)
            ->firstOrFail();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/approvals/{$secondApproval->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $secondApproval->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Delivery order: per-type reviewer configuration
    // ─────────────────────────────────────────────────────────────────────

    public function test_delivery_order_uses_its_own_reviewer_configuration(): void
    {
        Setting::setValue('sales.delivery_order.default_requires_approval', true);
        Setting::setValue('sales.delivery_order.reviewer_user_ids', [$this->reviewer->id]);
        Setting::setValue('sales.delivery_order.reviewer_roles', []);

        $quotation = $this->makeDraftQuotation(500);

        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-delivery-order")
            ->assertCreated();

        $deliveryOrder = DeliveryOrder::query()->where('quotation_id', $quotation->id)->firstOrFail();
        $this->assertSame('pending', $deliveryOrder->approval_status);

        $approval = ApprovalRequest::query()
            ->where('request_type', SalesApprovalGate::REQUEST_TYPE_DELIVERY_ORDER)
            ->where('subject_id', $deliveryOrder->id)
            ->firstOrFail();

        $this->actingAs($this->reviewer)
            ->postJson("/api/v1/approvals/{$approval->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $deliveryOrder->fresh()->approval_status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // One userCanApprove rule for every sales request type
    // ─────────────────────────────────────────────────────────────────────

    public function test_user_can_approve_is_one_rule_for_every_sales_request_type(): void
    {
        $service = app(ApprovalService::class);

        $options = [
            SalesApprovalGate::REQUEST_TYPE_QUOTATION => 'sales.quotation',
            SalesApprovalGate::REQUEST_TYPE_SALES_ORDER => 'sales.sales_order',
            SalesApprovalGate::REQUEST_TYPE_INVOICE => 'sales.invoice_threshold',
            SalesApprovalGate::REQUEST_TYPE_DELIVERY_ORDER => 'sales.delivery_order',
            InvoiceApprovalService::REQUEST_TYPE => 'sales.invoice',
        ];

        foreach ($options as $requestType => $prefix) {
            Setting::setValue($prefix.'.reviewer_user_ids', [$this->reviewer->id]);
            Setting::setValue($prefix.'.reviewer_roles', []);

            $this->assertTrue(
                $service->userCanApprove($this->reviewer, $requestType, 2500.0),
                "Configured reviewer must be able to approve {$requestType}"
            );
            $this->assertFalse(
                $service->userCanApprove($this->viewer, $requestType, 2500.0),
                "Non-reviewer must not be able to approve {$requestType}"
            );

            Setting::setValue($prefix.'.reviewer_user_ids', []);
            Setting::setValue($prefix.'.reviewer_roles', []);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Data check: pending sales requests past their expiry
    // ─────────────────────────────────────────────────────────────────────

    public function test_pending_sales_requests_past_expiry_can_be_listed(): void
    {
        $quotation = $this->makeDraftQuotation(2000);

        $stale = ApprovalRequest::create([
            'request_type' => SalesApprovalGate::REQUEST_TYPE_QUOTATION,
            'subject_type' => Quotation::class,
            'subject_id' => $quotation->id,
            'amount' => 2000,
            'reason' => 'Stale quotation approval nobody could action',
            'requested_by' => $this->sales->id,
            'requested_at' => now()->subDays(10),
            'status' => 'pending',
            'expires_at' => now()->subDays(3),
        ]);

        // Not a sales request type — must not appear in the sales backlog.
        ApprovalRequest::create([
            'request_type' => 'refund',
            'subject_type' => 'PosOrder',
            'subject_id' => 999,
            'amount' => 50,
            'reason' => 'Stale POS refund',
            'requested_by' => $this->sales->id,
            'requested_at' => now()->subDays(10),
            'status' => 'pending',
            'expires_at' => now()->subDays(3),
        ]);

        // Already decided — not pending, must not appear.
        ApprovalRequest::create([
            'request_type' => SalesApprovalGate::REQUEST_TYPE_SALES_ORDER,
            'subject_type' => SalesOrder::class,
            'subject_id' => 999,
            'amount' => 50,
            'reason' => 'Already approved',
            'requested_by' => $this->sales->id,
            'requested_at' => now()->subDays(10),
            'status' => 'approved',
            'expires_at' => now()->subDays(3),
        ]);

        $staleRequests = app(ApprovalService::class)->pendingSalesRequestsPastExpiry();

        $this->assertCount(1, $staleRequests);
        $this->assertSame($stale->id, $staleRequests->first()->id);
    }
}