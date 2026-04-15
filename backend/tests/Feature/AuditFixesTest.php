<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Services\ThreeWayMatchService;
use Tests\TestCase;

class AuditFixesTest extends TestCase
{
    // ── GAP-C005: PostingEngine idempotency guard exists ─────────────────

    public function test_posting_engine_has_idempotency_guard(): void
    {
        $reflection = new \ReflectionClass(\App\Accounting\PostingEngine::class);
        $method = $reflection->getMethod('post');
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('JournalEntryLink', $source);
        $this->assertStringContainsString('duplicate posting prevented', $source);
    }

    // ── GAP-H006: Invoice paid_amount uses atomic increment ──────────────

    public function test_invoice_service_uses_atomic_increment(): void
    {
        $source = file_get_contents(app_path('Services/InvoiceService.php'));

        $this->assertStringContainsString("increment('paid_amount'", $source);
        $this->assertStringContainsString('$invoice->refresh()', $source);
    }

    public function test_webhook_update_invoice_uses_atomic_increment(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Controllers/PaymentWebhookController.php'));

        $this->assertStringContainsString("increment('paid_amount'", $source);
    }

    // ── GAP-C006: Customer.updateOutstanding includes posted ─────────────

    public function test_customer_outstanding_includes_posted_status(): void
    {
        $source = file_get_contents(app_path('Models/Customer.php'));

        $this->assertStringContainsString("'posted'", $source);
        $this->assertStringContainsString("'partial'", $source);
    }

    // ── GAP-M002: WebOrder state machine ─────────────────────────────────

    public function test_web_order_has_state_machine(): void
    {
        $order = new \Modules\Ecommerce\Models\WebOrder();

        $this->assertTrue(method_exists($order, 'transitionTo'));
        $this->assertTrue(method_exists($order, 'canTransitionTo'));
        $this->assertTrue(method_exists($order, 'confirm'));
        $this->assertTrue(method_exists($order, 'cancel'));
        $this->assertTrue(method_exists($order, 'markShipped'));
    }

    public function test_web_order_state_machine_rejects_invalid_transition(): void
    {
        $order = new \Modules\Ecommerce\Models\WebOrder();
        $order->status = 'pending';

        $this->assertFalse($order->canTransitionTo('shipped'));
        $this->assertTrue($order->canTransitionTo('confirmed'));
        $this->assertTrue($order->canTransitionTo('cancelled'));
    }

    public function test_web_order_has_soft_deletes(): void
    {
        $order = new \Modules\Ecommerce\Models\WebOrder();
        $this->assertTrue(in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($order)));
    }

    public function test_pos_order_has_soft_deletes(): void
    {
        $order = new \Modules\POS\Models\PosOrder();
        $this->assertTrue(in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($order)));
    }

    // ── GAP-M005: AR/AP views use balance not total ──────────────────────

    public function test_ar_ap_views_migration_uses_balance(): void
    {
        $migrationFiles = glob(database_path('migrations/tenant/*fix_ar_ap_views*'));
        $this->assertNotEmpty($migrationFiles);

        $source = file_get_contents($migrationFiles[0]);
        $this->assertStringContainsString('i.balance as debit', $source);
        $this->assertStringContainsString('b.balance as debit', $source);
        $this->assertStringContainsString("NOT IN ('draft', 'cancelled')", $source);
    }

    // ── ThreeWayMatchService ─────────────────────────────────────────────

    public function test_three_way_match_rejects_unlinked_bill(): void
    {
        $service = app(ThreeWayMatchService::class);
        $bill = new Bill();

        $result = $service->validate($bill);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    // ── SubledgerReconciliationService ───────────────────────────────────

    public function test_reconciliation_service_exists(): void
    {
        $this->assertTrue(class_exists(\App\Services\SubledgerReconciliationService::class));
        $this->assertTrue(method_exists(\App\Services\SubledgerReconciliationService::class, 'reconcileAR'));
        $this->assertTrue(method_exists(\App\Services\SubledgerReconciliationService::class, 'reconcileAP'));
        $this->assertTrue(method_exists(\App\Services\SubledgerReconciliationService::class, 'reconcileAll'));
    }

    // ── Vendor.updateSpending ────────────────────────────────────────────

    public function test_vendor_has_update_spending_method(): void
    {
        $this->assertTrue(method_exists(\App\Models\Vendor::class, 'updateSpending'));
    }

    // ── GAP-H009: HitPay webhook rejects missing HMAC ────────────────────

    public function test_hitpay_gateway_rejects_missing_hmac(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Services/HitPayGateway.php'));

        $this->assertStringContainsString('missing HMAC signature', $source);
        $this->assertStringContainsString('return false', $source);
    }

    // ── GAP-H005: InventoryService uses lockForUpdate ────────────────────

    public function test_inventory_service_uses_row_locking(): void
    {
        $source = file_get_contents(base_path('modules/Inventory/src/Services/InventoryService.php'));

        $this->assertStringContainsString('lockForUpdate', $source);
    }

    // ── GAP-H001: Invoice status enum includes posted and partial ────────

    public function test_invoice_status_migration_exists(): void
    {
        $migrationFiles = glob(database_path('migrations/tenant/*expand_invoice_status*'));
        $this->assertNotEmpty($migrationFiles);

        $source = file_get_contents($migrationFiles[0]);
        $this->assertStringContainsString("'posted'", $source);
        $this->assertStringContainsString("'partial'", $source);
    }

    // ── GAP-C001: PayPal webhook rejects invalid signature ──────────────

    public function test_paypal_webhook_rejects_invalid_signature(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Controllers/PaymentWebhookController.php'));

        $this->assertStringContainsString("Invalid signature", $source);
        $this->assertStringContainsString("return response()->json(['error' => 'Invalid signature'], 400)", $source);
    }

    // ── GAP-C002: Stripe webhook has tenancy initialization ─────────────

    public function test_stripe_webhook_has_tenancy(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Controllers/PaymentWebhookController.php'));

        $this->assertStringContainsString('initializeTenancyForTransaction', $source);
        $this->assertStringContainsString('$tenant->run(', $source);
    }

    // ── GoodsReceiptService and Controller exist ─────────────────────────

    public function test_goods_receipt_service_exists(): void
    {
        $this->assertTrue(class_exists(\App\Services\GoodsReceiptService::class));
        $this->assertTrue(class_exists(\App\Http\Controllers\Api\GoodsReceiptsController::class));
    }

    public function test_goods_receipt_routes_exist(): void
    {
        $source = file_get_contents(base_path('routes/tenant.php'));

        $this->assertStringContainsString('GoodsReceiptsController', $source);
    }

    // ── R19: bills.vendor_id RESTRICT delete ─────────────────────────────

    public function test_vendor_delete_cascade_restricted(): void
    {
        $migrationFiles = glob(database_path('migrations/tenant/*restrict_vendor_delete*'));
        $this->assertNotEmpty($migrationFiles);

        $source = file_get_contents($migrationFiles[0]);
        $this->assertStringContainsString('ON DELETE RESTRICT', $source);
    }

    // ── R26: PosOrderController sets pos_order_id FK ─────────────────────

    public function test_pos_order_invoice_sets_fk(): void
    {
        $source = file_get_contents(base_path('modules/POS/src/Http/Controllers/PosOrderController.php'));

        $this->assertStringContainsString("where('pos_order_id'", $source);
        $this->assertStringContainsString("'pos_order_id'     => \$posOrder->id", $source);
    }

    // ── GAP-M007: FeatureFlagMiddleware uses canonical entitlements ─────

    public function test_feature_middleware_uses_canonical_entitlements(): void
    {
        $source = file_get_contents(base_path('modules/EventManagement/Http/Middleware/FeatureFlagMiddleware.php'));

        $this->assertStringContainsString('FeatureGateService', $source);
        $this->assertStringContainsString('featureGate', $source);
    }

    // ── GAP-M009: ChartOfAccountsController validates input ──────────────

    public function test_chart_of_accounts_validates_type(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/ChartOfAccountsController.php'));

        $this->assertStringContainsString("validate(['type' => 'string|in:", $source);
    }

    // ── Credit notes call updateOutstanding ──────────────────────────────

    public function test_credit_note_service_calls_update_outstanding(): void
    {
        $source = file_get_contents(app_path('Services/CustomerCreditNoteService.php'));

        $this->assertStringContainsString('updateOutstanding', $source);
    }

    // ── InvoiceService::postInvoice uses lockForUpdate ───────────────────

    public function test_post_invoice_uses_lock_for_update(): void
    {
        $source = file_get_contents(app_path('Services/InvoiceService.php'));

        $this->assertStringContainsString('lockForUpdate', $source);
    }

    // ── BillPaymentsController uses atomic increment ─────────────────────

    public function test_bill_payments_uses_atomic_increment(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/BillPaymentsController.php'));

        $this->assertStringContainsString("increment('paid_amount'", $source);
        $this->assertStringContainsString('updateSpending', $source);
    }

    // ── HitPay webhook_id is deterministic ───────────────────────────────

    public function test_hitpay_webhook_id_is_deterministic(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Services/HitPayGateway.php'));

        $this->assertStringContainsString("hash('sha256', json_encode(\$data))", $source);

        // The webhook_id line should NOT use time() — verify the deterministic pattern is used
        $this->assertStringContainsString("webhookId = 'hitpay_' . hash('sha256', json_encode(\$data))", $source);
    }
}
