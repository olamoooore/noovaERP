<?php

namespace Tests\Feature;

use App\Accounting\Events\ApprovalDecisionEvent;
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
use App\Platform\Notifications\NotificationLog;
use App\Services\SalesApprovalGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #650 — threshold approval requests notified nobody and did not resume
 * the action after the decision.
 *
 * The gate's recorded design (#180, #646) keeps the gated quotation / sales
 * order in `draft` (edit invalidation depends on it), so the resume is the
 * issue's allowed alternative: the approved request records that the gated
 * action can now be continued, the requester is told (in-app + mail), and the
 * document's existing confirm/convert route completes it with one click —
 * reusing the approved request and never double-executing.
 *
 * These are router-level tests (repo SOP §3): the request is raised by the real
 * confirm/convert route, decided through POST /api/v1/approvals/{id}/approve
 * or /reject, then the Continue action is re-run through its own route.
 */
class SalesApprovalDecisionResumeTest extends TenantTestCase
{
    protected User $sales;

    protected User $reviewer;

    protected Customer $customer;

    protected Product $product;

    protected Warehouse $warehouse;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $tenant = Tenant::withoutEvents(function () {
            return Tenant::factory()->create(['id' => (string) Str::uuid()]);
        });

        $this->tenantId = $tenant->id;
        $this->bindTenantContext($this->tenantId);

        $this->assertTenantSchemaReady([
            'customers', 'products', 'sales_orders', 'quotations',
            'approval_requests', 'settings', 'notification_logs',
        ]);

        $this->sales = $this->makeUser('sales-requester');
        $this->reviewer = $this->makeUser('sales-reviewer');

        $this->customer = Customer::factory()->create([
            'payment_terms' => 30,
            'credit_limit' => 100000,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Resume Widget',
            'sku' => 'RES-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        $this->warehouse = Warehouse::factory()->create(['is_default' => true]);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Setting::setValue('sales.invoice.approval_expiry_days', null);
        Setting::setValue('sales.quotation.reviewer_user_ids', []);
        Setting::setValue('sales.quotation.reviewer_roles', []);
        Setting::setValue('sales.sales_order.reviewer_user_ids', []);
        Setting::setValue('sales.sales_order.reviewer_roles', []);
    }

    private function makeUser(string $label): User
    {
        $user = User::create([
            'name' => ucfirst($label).' '.uniqid(),
            'email' => $label.'-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
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

    private function raiseQuotationApproval(Quotation $quotation): ApprovalRequest
    {
        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertStatus(202);

        return ApprovalRequest::query()
            ->where('request_type', SalesApprovalGate::REQUEST_TYPE_QUOTATION)
            ->where('subject_id', $quotation->id)
            ->firstOrFail();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Approvers are notified when the request is raised
    // ─────────────────────────────────────────────────────────────────────

    public function test_configured_approver_is_notified_when_a_quotation_threshold_request_is_raised(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);
        Setting::setValue('sales.quotation.reviewer_user_ids', [$this->reviewer->id]);

        $quotation = $this->makeDraftQuotation(2500);
        $approval = $this->raiseQuotationApproval($quotation);

        $log = NotificationLog::query()
            ->where('user_id', $this->reviewer->id)
            ->where('event', 'approval.requested')
            ->first();

        $this->assertNotNull(
            $log,
            'The configured approver must receive an approval.requested notification when the gate raises a request.'
        );
        $this->assertSame('mail', $log->channel, 'The approver notification must go out on the mail channel (in-app row + email).');
        $this->assertSame('sent', $log->status, 'The email leg must be dispatched, not left pending.');
        $this->assertSame($approval->id, $log->data['approval_request_id'] ?? null);
        $this->assertSame('quotation', $log->data['primary']['type'] ?? null);
        $this->assertSame($quotation->id, (int) ($log->data['primary']['id'] ?? 0));
    }

    public function test_configured_approver_is_notified_when_a_sales_order_threshold_request_is_raised(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);
        Setting::setValue('sales.sales_order.reviewer_user_ids', [$this->reviewer->id]);

        $order = $this->makeDraftSalesOrder(2500);

        $this->actingAs($this->sales)
            ->postJson("/api/v1/sales/orders/{$order->id}/confirm")
            ->assertStatus(202);

        $approval = ApprovalRequest::query()
            ->where('request_type', SalesApprovalGate::REQUEST_TYPE_SALES_ORDER)
            ->where('subject_id', $order->id)
            ->firstOrFail();

        $log = NotificationLog::query()
            ->where('user_id', $this->reviewer->id)
            ->where('event', 'approval.requested')
            ->first();

        $this->assertNotNull($log, 'The configured approver must be notified for a sales-order gate request.');
        $this->assertSame($approval->id, $log->data['approval_request_id'] ?? null);
        $this->assertSame('sales_order', $log->data['primary']['type'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Quotation confirm: notified + one-click Continue
    // ─────────────────────────────────────────────────────────────────────

    public function test_quotation_confirm_decision_notifies_the_requester_with_a_continue_signal(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);
        Setting::setValue('sales.quotation.reviewer_user_ids', [$this->reviewer->id]);

        $quotation = $this->makeDraftQuotation(2500);
        $approval = $this->raiseQuotationApproval($quotation);

        $this->actingAs($this->reviewer)
            ->postJson("/api/v1/approvals/{$approval->id}/approve", ['notes' => 'Approved in test'])
            ->assertOk();

        // #646: the approved document stays draft and editable — the resume is
        // a Continue signal, not an automatic confirmation.
        $this->assertSame('draft', $quotation->fresh()->status);
        $this->assertNull($quotation->fresh()->sales_order_id);

        $log = NotificationLog::query()
            ->where('user_id', $this->sales->id)
            ->where('event', 'approval.approved')
            ->first();

        $this->assertNotNull($log, 'The requester must be notified when their request is approved.');
        $this->assertSame('quotation', $log->data['primary']['type'] ?? null);
        $this->assertSame($quotation->id, (int) ($log->data['primary']['id'] ?? 0));
        $this->assertTrue(
            (bool) ($log->data['resume_available'] ?? false),
            'The requester notification must expose the one-click Continue availability.'
        );
        $this->assertSame('confirm', $log->data['resume_action'] ?? null);

        // The approvals API response carries the same continuation verdict for
        // the inbox UI to render a Continue button.
        $metadata = $approval->fresh()->metadata ?? [];
        $this->assertTrue((bool) ($metadata['resume']['available'] ?? false));
        $this->assertSame('confirm', $metadata['resume']['action'] ?? null);
    }

    public function test_quotation_confirm_is_completed_by_the_continue_click_and_never_doubles(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);
        Setting::setValue('sales.quotation.reviewer_user_ids', [$this->reviewer->id]);

        $quotation = $this->makeDraftQuotation(2500);
        $approval = $this->raiseQuotationApproval($quotation);

        $this->actingAs($this->reviewer)
            ->postJson("/api/v1/approvals/{$approval->id}/approve")
            ->assertOk();

        // One click on the document's Confirm action completes the gated work.
        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        $quotation->refresh();
        $this->assertSame('confirmed', $quotation->status);
        $this->assertNotNull($quotation->sales_order_id);
        $this->assertSame(1, SalesOrder::count());
        $this->assertSame(
            1,
            ApprovalRequest::query()
                ->where('request_type', SalesApprovalGate::REQUEST_TYPE_QUOTATION)
                ->where('subject_id', $quotation->id)
                ->count(),
            'The approved request must be reused by the Continue click, never duplicated.'
        );

        // A repeated Continue click cannot double-confirm: the document is no
        // longer in a confirmable status.
        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertStatus(403);
        $this->assertSame(1, SalesOrder::count());
    }

    public function test_replaying_an_approved_quotation_decision_keeps_the_document_untouched(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);
        Setting::setValue('sales.quotation.reviewer_user_ids', [$this->reviewer->id]);

        $quotation = $this->makeDraftQuotation(2500);
        $approval = $this->raiseQuotationApproval($quotation);

        $this->actingAs($this->reviewer)
            ->postJson("/api/v1/approvals/{$approval->id}/approve")
            ->assertOk();

        // A replayed decision event (e.g. an at-least-once delivery) must not
        // execute anything by itself.
        ApprovalDecisionEvent::dispatch($approval->fresh(), ApprovalDecisionEvent::ACTION_APPROVED, $this->reviewer);

        $quotation->refresh();
        $this->assertSame('draft', $quotation->status);
        $this->assertNull($quotation->sales_order_id);
        $this->assertSame(0, SalesOrder::count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Quotation convert: notified + Continue completes the conversion
    // ─────────────────────────────────────────────────────────────────────

    public function test_quotation_conversion_decision_signals_convert_and_the_continue_click_converts(): void
    {
        // Quotation first confirmed normally, then the threshold is enabled.
        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        $quotation = $this->makeDraftQuotation(2500);
        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/confirm")
            ->assertOk();

        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);
        Setting::setValue('sales.quotation.reviewer_user_ids', [$this->reviewer->id]);

        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-invoice")
            ->assertStatus(202);

        $approval = ApprovalRequest::query()
            ->where('request_type', SalesApprovalGate::REQUEST_TYPE_QUOTATION)
            ->where('subject_id', $quotation->id)
            ->firstOrFail();

        $this->actingAs($this->reviewer)
            ->postJson("/api/v1/approvals/{$approval->id}/approve")
            ->assertOk();

        $log = NotificationLog::query()
            ->where('user_id', $this->sales->id)
            ->where('event', 'approval.approved')
            ->first();

        $this->assertNotNull($log);
        $this->assertTrue((bool) ($log->data['resume_available'] ?? false));
        $this->assertSame('convert', $log->data['resume_action'] ?? null);

        // One click on Create Invoice completes the gated conversion.
        $this->actingAs($this->sales)
            ->postJson("/api/v1/quotations/{$quotation->id}/create-invoice")
            ->assertCreated();

        $this->assertGreaterThan(0, Invoice::where('quotation_id', $quotation->id)->count());
        $this->assertSame(1, ApprovalRequest::count(), 'The approved request is reused by the Continue click.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sales order confirm: notified + one-click Continue
    // ─────────────────────────────────────────────────────────────────────

    public function test_sales_order_confirm_decision_notifies_the_requester_and_continue_completes_it(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);
        Setting::setValue('sales.sales_order.reviewer_user_ids', [$this->reviewer->id]);

        $order = $this->makeDraftSalesOrder(2500);

        $this->actingAs($this->sales)
            ->postJson("/api/v1/sales/orders/{$order->id}/confirm")
            ->assertStatus(202);

        $approval = ApprovalRequest::query()
            ->where('request_type', SalesApprovalGate::REQUEST_TYPE_SALES_ORDER)
            ->where('subject_id', $order->id)
            ->firstOrFail();

        $this->assertSame('draft', $order->fresh()->status);

        $this->actingAs($this->reviewer)
            ->postJson("/api/v1/approvals/{$approval->id}/approve")
            ->assertOk();

        $this->assertSame('draft', $order->fresh()->status, 'The approved order stays draft (gate design, #646).');

        $log = NotificationLog::query()
            ->where('user_id', $this->sales->id)
            ->where('event', 'approval.approved')
            ->first();

        $this->assertNotNull($log);
        $this->assertTrue((bool) ($log->data['resume_available'] ?? false));
        $this->assertSame('confirm', $log->data['resume_action'] ?? null);
        $this->assertSame('sales_order', $log->data['primary']['type'] ?? null);

        // One click on the order's Confirm action completes the gated work.
        $this->actingAs($this->sales)
            ->postJson("/api/v1/sales/orders/{$order->id}/confirm")
            ->assertOk();

        $this->assertSame('confirmed', $order->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Rejection: requester told, nothing resumed
    // ─────────────────────────────────────────────────────────────────────

    public function test_rejected_quotation_request_notifies_the_requester_and_stays_draft(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);
        Setting::setValue('sales.quotation.reviewer_user_ids', [$this->reviewer->id]);

        $quotation = $this->makeDraftQuotation(2500);
        $approval = $this->raiseQuotationApproval($quotation);

        $this->actingAs($this->reviewer)
            ->postJson("/api/v1/approvals/{$approval->id}/reject", ['reason' => 'Price is wrong on this quotation'])
            ->assertOk();

        $this->assertSame('draft', $quotation->fresh()->status);
        $this->assertNull($quotation->fresh()->sales_order_id);

        $log = NotificationLog::query()
            ->where('user_id', $this->sales->id)
            ->where('event', 'approval.rejected')
            ->first();

        $this->assertNotNull($log, 'The requester must be notified when their request is rejected.');
        $this->assertStringContainsString(
            'Price is wrong',
            (string) ($log->data['reason'] ?? ''),
            'The rejection reason must travel with the requester notification.'
        );
        $this->assertFalse((bool) ($log->data['resume_available'] ?? false));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Expiry comes from tenant settings
    // ─────────────────────────────────────────────────────────────────────

    public function test_threshold_request_expiry_uses_the_configured_setting(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);
        Setting::setValue('sales.quotation.reviewer_user_ids', [$this->reviewer->id]);
        Setting::setValue('sales.invoice.approval_expiry_days', 3);

        $quotation = $this->makeDraftQuotation(2500);
        $approval = $this->raiseQuotationApproval($quotation);

        $this->assertNotNull($approval->expires_at);
        $this->assertTrue(
            $approval->expires_at->between(now()->addDays(3)->subMinutes(5), now()->addDays(3)->addMinutes(5)),
            "Approval expiry must honour the tenant setting (3 days); got {$approval->expires_at}."
        );
    }

    public function test_threshold_request_expiry_defaults_to_seven_days_without_a_setting(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, 1000);
        Setting::setValue('sales.quotation.reviewer_user_ids', [$this->reviewer->id]);
        Setting::setValue('sales.invoice.approval_expiry_days', null);

        $quotation = $this->makeDraftQuotation(2500);
        $approval = $this->raiseQuotationApproval($quotation);

        $this->assertNotNull($approval->expires_at);
        $this->assertTrue(
            $approval->expires_at->between(now()->addDays(7)->subMinutes(5), now()->addDays(7)->addMinutes(5)),
            "Approval expiry must fall back to the previous 7-day window; got {$approval->expires_at}."
        );
    }
}
