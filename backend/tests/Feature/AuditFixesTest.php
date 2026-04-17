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

        $this->assertStringContainsString('PaymentTransactionCompletionService', $source);
        $this->assertStringContainsString('app(PaymentTransactionCompletionService::class)->complete(', $source);
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

    // ── GAP-C002: Webhooks require middleware-owned tenant context ──────

    public function test_stripe_webhook_requires_pre_resolved_tenant_context(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Controllers/PaymentWebhookController.php'));

        $this->assertStringContainsString('ensureTenantContext', $source);
        $this->assertStringContainsString('Tenant context not resolved', $source);
        $this->assertStringNotContainsString('initializeTenancyForTransaction', $source);
        $this->assertStringNotContainsString('$tenant->run(', $source);
    }

    public function test_payment_transactions_sync_shared_webhook_lookup(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Models/PaymentTransaction.php'));

        $this->assertStringContainsString('syncWebhookTenantLookup', $source);
        $this->assertStringContainsString('payment_webhook_tenant_lookups', $source);
    }

    // ── GoodsReceiptService and Controller exist ─────────────────────────

    public function test_goods_receipt_service_exists(): void
    {
        $this->assertTrue(class_exists(\App\Services\GoodsReceiptService::class));
        $this->assertTrue(class_exists(\App\Http\Controllers\Api\GoodsReceiptsController::class));
    }

    public function test_goods_receipt_routes_exist(): void
    {
        $source = file_get_contents(base_path('modules/Inventory/routes/api.php'));

        $this->assertStringContainsString('GoodsReceiptsController', $source);
        $this->assertStringContainsString("Route::post('/goods-receipts'", $source);
    }

    public function test_public_tenant_routes_no_longer_expose_goods_receipts(): void
    {
        $source = file_get_contents(base_path('routes/tenant.php'));

        $this->assertStringNotContainsString("Route::post('/goods-receipts'", $source);
        $this->assertStringNotContainsString("Route::post('/goods-receipts/{goodsReceipt}/confirm'", $source);
    }

    public function test_web_checkout_posts_paid_orders_inline(): void
    {
        $source = file_get_contents(base_path('modules/Ecommerce/src/Http/Controllers/WebCheckoutController.php'));

        $this->assertStringContainsString('PaymentTransactionCompletionService', $source);
        $this->assertStringContainsString('completionService->complete(', $source);
        $this->assertStringContainsString('PaymentTransaction::where', $source);
        $this->assertStringContainsString("'status' => 'completed'", $source);
    }

    public function test_web_checkout_requires_successful_invoice_posting_after_payment_confirmation(): void
    {
        $source = file_get_contents(base_path('modules/Ecommerce/src/Http/Controllers/WebCheckoutController.php'));

        $this->assertStringContainsString('Payment transaction not found for confirmed web order payment.', $source);
        $this->assertStringContainsString('completionService->complete(', $source);
    }

    public function test_fix_stuck_web_orders_delegates_to_canonical_completion_service(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/Console/Commands/FixStuckWebOrders.php'));

        $this->assertStringContainsString('PaymentTransactionCompletionService', $source);
        $this->assertStringContainsString('completionService->complete(', $source);
        $this->assertStringNotContainsString('recordChannelPayment($invoice', $source);
        $this->assertStringNotContainsString('postToAccounting(', $source);
    }

    public function test_process_all_web_orders_delegates_transaction_repair_to_canonical_completion_service(): void
    {
        $source = file_get_contents(base_path('modules/Ecommerce/Console/Commands/ProcessAllWebOrders.php'));

        $this->assertStringContainsString('PaymentTransactionCompletionService', $source);
        $this->assertStringContainsString('completionService->complete(', $source);
        $this->assertStringNotContainsString('convertToInvoice()', $source);
        $this->assertStringNotContainsString('postPaidOrder(', $source);
    }

    public function test_web_checkout_persists_fulfillment_warehouse_context(): void
    {
        $source = file_get_contents(base_path('modules/Ecommerce/src/Http/Controllers/WebCheckoutController.php'));

        $this->assertStringContainsString('fulfillment_warehouse_id', $source);
        $this->assertStringContainsString('No single warehouse can fulfill all requested items', $source);
        $this->assertStringContainsString('No active warehouse can fulfill all requested items', $source);
    }

    public function test_web_order_posting_service_requires_explicit_warehouse_context(): void
    {
        $source = file_get_contents(base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php'));

        $this->assertStringContainsString('resolveInventoryWarehouseId()', $source);
        $this->assertStringContainsString('no fulfillment warehouse context', $source);
        $this->assertStringNotContainsString('Warehouse::getDefault()', $source);
    }

    public function test_web_order_model_can_resolve_inventory_warehouse_from_documents(): void
    {
        $source = file_get_contents(base_path('modules/Ecommerce/src/Models/WebOrder.php'));

        $this->assertStringContainsString('function resolveInventoryWarehouseId', $source);
        $this->assertStringContainsString('StockReservation::query()', $source);
        $this->assertStringContainsString('StockMovement::query()', $source);
    }

    public function test_inventory_api_no_longer_exposes_raw_stock_mutation_routes(): void
    {
        $source = file_get_contents(base_path('modules/Inventory/routes/api.php'));

        $this->assertStringNotContainsString("Route::post('/in'", $source);
        $this->assertStringNotContainsString("Route::post('/out'", $source);
        $this->assertStringNotContainsString("Route::post('/adjust'", $source);
    }

    public function test_stock_controller_marks_direct_mutation_endpoints_disabled(): void
    {
        $source = file_get_contents(base_path('modules/Inventory/src/Http/Controllers/StockController.php'));

        $this->assertStringContainsString('Direct stock-in mutations are disabled', $source);
        $this->assertStringContainsString('Direct stock-out mutations are disabled', $source);
        $this->assertStringContainsString('Direct stock adjustments are disabled', $source);
    }

    public function test_pos_payment_posts_gl_before_stock_deduction(): void
    {
        $source = file_get_contents(base_path('modules/POS/src/Services/PosPaymentCompletionService.php'));

        $completePosition = strpos($source, 'posPostingService->postPaidOrder(');
        $adjustStockPosition = strpos($source, 'adjustStock(');

        $this->assertNotFalse($completePosition);
        $this->assertNotFalse($adjustStockPosition);
        $this->assertLessThan($adjustStockPosition, $completePosition);
    }

    public function test_iot_webhook_uses_duplicate_guards_and_canonical_sale_movements(): void
    {
        $source = file_get_contents(base_path('modules/IoT/src/Jobs/ProcessPaymentWebhook.php'));

        $this->assertStringContainsString('Skipping already-paid IoT webhook order', $source);
        $this->assertStringContainsString('Skipping duplicate IoT payment webhook', $source);
        $this->assertStringContainsString('PosPaymentCompletionService', $source);
        $this->assertStringContainsString('paymentCompletionService->complete(', $source);
    }

    public function test_iot_terminal_uses_duplicate_guards_and_canonical_sale_movements(): void
    {
        $source = file_get_contents(base_path('modules/IoT/src/Http/Controllers/IoTTerminalController.php'));

        $this->assertStringContainsString('Skipping already-paid IoT terminal order', $source);
        $this->assertStringContainsString('Skipping duplicate IoT terminal payment', $source);
        $this->assertStringContainsString('PosPaymentCompletionService', $source);
        $this->assertStringContainsString('paymentCompletionService->complete(', $source);
    }

    public function test_payment_service_no_longer_marks_transactions_processed_on_gateway_confirm_only(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Services/PaymentService.php'));

        $this->assertStringContainsString("'processed_at' => null", $source);
    }

    public function test_payment_webhook_uses_processed_at_as_local_completion_guard(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Controllers/PaymentWebhookController.php'));

        $this->assertStringContainsString('processed_at set', $source);
        $this->assertStringContainsString("'status' => 'processing'", $source);
        $this->assertStringContainsString("'status' => 'completed'", $source);
        $this->assertStringContainsString("'status' => 'pending'", $source);
        $this->assertStringContainsString('PaymentTransactionCompletionService::class', $source);
    }

    public function test_payment_webhook_propagates_web_order_processing_failures(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Services/PaymentTransactionCompletionService.php'));

        $this->assertStringContainsString('No invoice, POS order, or web order found for transaction.', $source);
        $this->assertStringContainsString('Invoice not created for web order.', $source);
        $this->assertStringContainsString('Web order payment was confirmed but posting did not produce a journal entry.', $source);
    }

    public function test_payment_webhook_no_longer_owns_web_order_completion_logic(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Controllers/PaymentWebhookController.php'));

        $this->assertStringContainsString('PaymentTransactionCompletionService::class', $source);
        $this->assertStringNotContainsString('processWebOrderPayment(', $source);
        $this->assertStringNotContainsString('postToAccounting(', $source);
        $this->assertStringNotContainsString('awardLoyaltyPoints(', $source);
        $this->assertStringNotContainsString('updateCustomerStats(', $source);
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

    public function test_feature_controller_fails_closed_without_tenant_context_and_no_legacy_fallback(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/FeatureController.php'));

        $this->assertStringContainsString("return response()->json(['message' => 'No tenant context available'], 403);", $source);
        $this->assertStringNotContainsString('TenantFeature', $source);
    }

    public function test_feature_controller_no_longer_self_grants_canonical_entitlements(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/FeatureController.php'));

        $this->assertStringNotContainsString('TenantConfigurationService', $source);
        $this->assertStringNotContainsString('syncToCanonicalEntitlements', $source);
        $this->assertStringNotContainsString('override_include', $source);
        $this->assertStringContainsString('Feature entitlement changes must be managed through plan configuration.', $source);
    }

    // ── GAP-M009: ChartOfAccountsController validates input ──────────────

    public function test_chart_of_accounts_validates_type(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/ChartOfAccountsController.php'));

        $this->assertStringContainsString("validate(['type' => 'string|in:", $source);
    }

    // ── Credit notes call updateOutstanding ──────────────────────────────

    public function test_credit_note_service_delegates_invoice_balance_to_invoice_service(): void
    {
        $source = file_get_contents(app_path('Services/CustomerCreditNoteService.php'));

        $this->assertStringContainsString('applyRefundToInvoice', $source,
            'CustomerCreditNoteService must delegate invoice balance updates to InvoiceService::applyRefundToInvoice.');
    }

    // ── InvoiceService::postInvoice uses lockForUpdate ───────────────────

    public function test_post_invoice_uses_lock_for_update(): void
    {
        $source = file_get_contents(app_path('Services/InvoiceService.php'));

        $this->assertStringContainsString('lockForUpdate', $source);
    }

    public function test_invoice_service_resolves_inventory_warehouse_from_source_documents(): void
    {
        $source = file_get_contents(app_path('Services/InvoiceService.php'));

        $this->assertStringContainsString('resolveInventoryWarehouseId', $source);
        $this->assertStringContainsString('resolveSalesOrderWarehouseId', $source);
        $this->assertStringContainsString('Cannot post invoice', $source);
        $this->assertStringNotContainsString('Warehouse::getDefault()', $source);
    }

    // ── BillPaymentsController uses atomic increment ─────────────────────
    // Workstream 1 (2026-04-15): Logic moved to BillPaymentService

    public function test_bill_payments_uses_atomic_increment(): void
    {
        // The controller now delegates to BillPaymentService, which uses atomic increment
        $controllerSource = file_get_contents(app_path('Http/Controllers/Api/BillPaymentsController.php'));
        $serviceSource = file_get_contents(app_path('Services/BillPaymentService.php'));

        // Controller should delegate to service
        $this->assertStringContainsString('BillPaymentService', $controllerSource);

        // Service should use atomic increment for bill payments
        $this->assertStringContainsString("increment('paid_amount'", $serviceSource);
        $this->assertStringContainsString('updateSpending', $serviceSource);
    }

    // ── HitPay webhook_id is deterministic ───────────────────────────────

    public function test_hitpay_webhook_id_is_deterministic(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Services/HitPayGateway.php'));

        $this->assertStringContainsString("hash('sha256', json_encode(\$data))", $source);

        // The webhook_id line should NOT use time() — verify the deterministic pattern is used
        $this->assertStringContainsString("webhookId = 'hitpay_' . hash('sha256', json_encode(\$data))", $source);
    }

    // ── Workstream 1: Canonical Posting Control Plane ─────────────────────
    // GAP-C001: BillPaymentsController delegates to BillPaymentService

    public function test_bill_payments_controller_delegates_to_service(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/BillPaymentsController.php'));

        // Should inject BillPaymentService, not PostingEngine
        $this->assertStringContainsString('BillPaymentService', $source);
        $this->assertStringNotContainsString('PostingEngineInterface $postingEngine', $source);

        // Store should delegate to recordPayment
        $this->assertStringContainsString('billPaymentService->recordPayment(', $source);

        // Update should delegate to updateDraftPayment
        $this->assertStringContainsString('billPaymentService->updateDraftPayment(', $source);

        // Destroy should delegate to deleteDraftPayment
        $this->assertStringContainsString('billPaymentService->deleteDraftPayment(', $source);
    }

    public function test_import_controller_vendor_payments_delegates_to_service(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/ImportController.php'));

        // Should inject BillPaymentService
        $this->assertStringContainsString('BillPaymentService', $source);

        // Import vendor payments should delegate to recordPayment
        $this->assertStringContainsString('billPaymentService->recordPayment(', $source);

        // Should no longer create BillPayment directly
        $this->assertStringNotContainsString('BillPayment::create(', $source);
    }

    public function test_payment_controller_delegates_completion_to_service(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Controllers/PaymentController.php'));

        // Should inject PaymentTransactionCompletionService
        $this->assertStringContainsString('PaymentTransactionCompletionService', $source);

        // Confirm should delegate to complete
        $this->assertStringContainsString('completionService->complete(', $source);
    }

    public function test_bill_payment_service_exists(): void
    {
        $this->assertTrue(class_exists(\App\Services\BillPaymentService::class));
        $this->assertTrue(method_exists(\App\Services\BillPaymentService::class, 'recordPayment'));
        $this->assertTrue(method_exists(\App\Services\BillPaymentService::class, 'updateDraftPayment'));
        $this->assertTrue(method_exists(\App\Services\BillPaymentService::class, 'deleteDraftPayment'));
    }

    public function test_payment_transaction_completion_service_exists(): void
    {
        $this->assertTrue(class_exists(\Modules\PaymentGateway\Services\PaymentTransactionCompletionService::class));
        $this->assertTrue(method_exists(\Modules\PaymentGateway\Services\PaymentTransactionCompletionService::class, 'complete'));
    }

    public function test_bill_payment_service_uses_posting_engine(): void
    {
        $source = file_get_contents(app_path('Services/BillPaymentService.php'));

        // Should inject PostingEngineInterface
        $this->assertStringContainsString('PostingEngineInterface', $source);

        // Should use PostingRequest
        $this->assertStringContainsString('PostingRequest', $source);
        $this->assertStringContainsString('PostingRequestLine', $source);
    }

    public function test_payment_transaction_completion_service_delegates_to_canonical_services(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Services/PaymentTransactionCompletionService.php'));

        // Should delegate invoice payments to InvoiceService
        $this->assertStringContainsString('InvoiceService', $source);
        $this->assertStringContainsString('invoiceService->recordPayment(', $source);

        // Should delegate POS payments to the shared POS completion service
        $this->assertStringContainsString('PosPaymentCompletionService', $source);
        $this->assertStringContainsString('posPaymentCompletionService->complete(', $source);

        // Should also own web-order completion convergence
        $this->assertStringContainsString('WebOrderPostingService', $source);
        $this->assertStringContainsString('completeWebOrderPayment', $source);
    }

    // ── Actor resolution hardening: canonical services use TenantUserHelper ──

    public function test_inventory_service_uses_tenant_user_helper(): void
    {
        $source = file_get_contents(base_path('modules/Inventory/src/Services/InventoryService.php'));

        $this->assertStringContainsString('TenantUserHelper::requireTenantUserId()', $source);
        $this->assertStringNotContainsString('auth()->id()', $source);
    }

    public function test_pos_posting_service_uses_tenant_user_helper(): void
    {
        $source = file_get_contents(base_path('modules/POS/src/Services/PosPostingService.php'));

        $this->assertStringContainsString('TenantUserHelper::resolveTenantUserId()', $source);
        $this->assertStringNotContainsString('auth()->id()', $source);
    }

    public function test_web_order_posting_service_uses_tenant_user_helper(): void
    {
        $source = file_get_contents(base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php'));

        $this->assertStringContainsString('TenantUserHelper::resolveTenantUserId()', $source);
        $this->assertStringNotContainsString('auth()->id()', $source);
    }

    public function test_pos_order_controller_uses_tenant_user_helper(): void
    {
        $source = file_get_contents(base_path('modules/POS/src/Http/Controllers/PosOrderController.php'));

        $this->assertStringContainsString('TenantUserHelper::resolveTenantUserId()', $source);

        $this->assertStringNotContainsString("'created_by' => auth()->id()", $source);
        $this->assertStringNotContainsString("'received_by' => auth()->id()", $source);
    }

    public function test_gdpr_export_job_uses_tenant_owned_storage_namespace(): void
    {
        $source = file_get_contents(base_path('modules/EventManagement/Jobs/GDPRExportJob.php'));

        $this->assertStringContainsString('gdpr_exports/', $source);
        $this->assertStringContainsString('currentTenantId()', $source);
        $this->assertStringContainsString('exportDirectory()', $source);
        $this->assertStringNotContainsString("storage_path('app/gdpr_exports')", $source);
    }

    // ── Critical Fix: Refunds now post GL reversal ────────────────────────

    public function test_payment_refund_service_exists(): void
    {
        $this->assertTrue(class_exists(\App\Services\PaymentRefundService::class));
        $this->assertTrue(method_exists(\App\Services\PaymentRefundService::class, 'processRefund'));
        $this->assertTrue(method_exists(\App\Services\PaymentRefundService::class, 'canRefund'));
        $this->assertTrue(method_exists(\App\Services\PaymentRefundService::class, 'getAvailableRefundAmount'));
    }

    public function test_payment_controller_refund_delegates_to_service(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Controllers/PaymentController.php'));

        // Should inject PaymentRefundService
        $this->assertStringContainsString('PaymentRefundService', $source);

        // Should delegate to processRefund
        $this->assertStringContainsString('refundService->processRefund(', $source);

        // Should no longer directly manipulate balances
        $this->assertStringNotContainsString('$transaction->increment(\'refunded_amount\'', $source);
    }

    public function test_payment_refund_service_uses_posting_engine(): void
    {
        $source = file_get_contents(app_path('Services/PaymentRefundService.php'));

        // Should inject PostingEngineInterface
        $this->assertStringContainsString('PostingEngineInterface', $source);

        // Should use ReversalRequest
        $this->assertStringContainsString('ReversalRequest', $source);

        // Should call postingEngine->reverse() or post()
        $this->assertStringContainsString('postingEngine->reverse(', $source);
    }

    public function test_payment_refund_creates_gl_entry(): void
    {
        $source = file_get_contents(app_path('Services/PaymentRefundService.php'));

        // Should create GL reversal entry
        $this->assertStringContainsString('postRefundEntryDirectly', $source);
        $this->assertStringContainsString('payment_refund', $source);
    }

    // ── Actor resolution hardening: all core services use TenantUserHelper ──

    public function test_all_core_services_use_tenant_user_helper(): void
    {
        $services = [
            'InventoryService' => base_path('modules/Inventory/src/Services/InventoryService.php'),
            'PosPostingService' => base_path('modules/POS/src/Services/PosPostingService.php'),
            'WebOrderPostingService' => base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php'),
            'VendorBillService' => app_path('Services/VendorBillService.php'),
            'VendorCreditNoteService' => app_path('Services/VendorCreditNoteService.php'),
            'BankReconciliationService' => app_path('Services/BankReconciliationService.php'),
            'DepreciationService' => app_path('Services/DepreciationService.php'),
            'GoodsReceiptService' => app_path('Services/GoodsReceiptService.php'),
            'OcrPromotionService' => app_path('Services/OcrPromotionService.php'),
            'StockReservationService' => base_path('modules/Inventory/src/Services/StockReservationService.php'),
            'PayrollJournalService' => base_path('modules/HRM/src/Services/PayrollJournalService.php'),
            'FxRevaluationService' => app_path('Accounting/Services/FxRevaluationService.php'),
        ];

        foreach ($services as $name => $path) {
            $source = file_get_contents($path);
            $this->assertStringContainsString('TenantUserHelper', $source,
                "{$name} must use TenantUserHelper for actor resolution.");
            $this->assertStringNotContainsString('auth()->id()', $source,
                "{$name} must not use auth()->id() directly.");
        }
    }

    public function test_all_core_models_use_tenant_user_helper(): void
    {
        $models = [
            'FiscalPeriod' => app_path('Models/FiscalPeriod.php'),
            'CustomerCreditNote' => app_path('Models/CustomerCreditNote.php'),
            'VendorCreditNote' => app_path('Models/VendorCreditNote.php'),
            'Product' => app_path('Models/Product.php'),
            'Customer' => app_path('Models/Customer.php'),
            'PosOrder' => base_path('modules/POS/src/Models/PosOrder.php'),
            'PosSession' => base_path('modules/POS/src/Models/PosSession.php'),
        ];

        foreach ($models as $name => $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('auth()->id()', $source,
                "{$name} model must not use auth()->id() directly.");
        }
    }

    public function test_no_auth_id_fallback_pattern_in_core(): void
    {
        // The `auth()->id() ?? 1` pattern was a cross-tenant collision risk
        $criticalFiles = [
            app_path('Accounting/Services/FxRevaluationService.php'),
            app_path('Models/FiscalPeriod.php'),
            base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php'),
            base_path('modules/EventManagement/Http/Controllers/ArtistManagementController.php'),
            base_path('modules/EventManagement/Http/Controllers/FraudAnalyticsController.php'),
        ];

        foreach ($criticalFiles as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('auth()->id() ?? 1', $source,
                basename($path) . " must not use auth()->id() ?? 1 fallback pattern.");
            $this->assertStringNotContainsString("auth()->id() ?? 'system'", $source,
                basename($path) . " must not use auth()->id() ?? 'system' fallback pattern.");
        }
    }

    // ── Workstream 2: Lock Override Permission Enforcement ──────────────────

    public function test_posting_engine_enforces_lock_override_permissions(): void
    {
        $source = file_get_contents(app_path('Accounting/PostingEngine.php'));

        // Should check canOverrideLockDate() for approver
        $this->assertStringContainsString('canOverrideLockDate($request->approvedBy)', $source);

        // Should check canOverrideLockDate() for actor
        $this->assertStringContainsString('canOverrideLockDate($actorId)', $source);

        // Should have permission error messages
        $this->assertStringContainsString('does not have permission to override lock dates', $source);
    }

    public function test_manual_journal_service_enforces_lock_override_permissions(): void
    {
        $source = file_get_contents(app_path('Services/ManualJournalPostingService.php'));

        // Should check canOverrideLockDate() for approver
        $this->assertStringContainsString('canOverrideLockDate($approvedBy)', $source);

        // Should check canOverrideLockDate() for actor
        $this->assertStringContainsString('canOverrideLockDate($userId)', $source);

        // Should have permission error messages
        $this->assertStringContainsString('does not have permission to override lock dates', $source);
    }

    public function test_lock_date_validation_service_has_can_override_method(): void
    {
        $this->assertTrue(method_exists(\App\Services\LockDateValidationService::class, 'canOverrideLockDate'));
    }

    public function test_lock_override_requires_different_approver(): void
    {
        $source = file_get_contents(app_path('Accounting/PostingEngine.php'));

        // Should prevent self-approval for lock overrides
        $this->assertStringContainsString('actorId === $request->approvedBy', $source);
        $this->assertStringContainsString('Self-approval of lock date overrides is not permitted', $source);
    }

    // ── Fine-Grained Entitlement Enforcement ───────────────────────────────

    public function test_accounting_access_service_has_fine_grained_methods(): void
    {
        $this->assertTrue(method_exists(\App\Services\AccountingAccessService::class, 'canPost'));
        $this->assertTrue(method_exists(\App\Services\AccountingAccessService::class, 'canReverse'));
        $this->assertTrue(method_exists(\App\Services\AccountingAccessService::class, 'canManageLocks'));
    }

    public function test_post_middleware_exists(): void
    {
        $this->assertTrue(class_exists(\App\Http\Middleware\EnsureAccountingPostAccess::class));
        $source = file_get_contents(app_path('Http/Middleware/EnsureAccountingPostAccess.php'));
        $this->assertStringContainsString('canPost(', $source);
    }

    public function test_reverse_middleware_exists(): void
    {
        $this->assertTrue(class_exists(\App\Http\Middleware\EnsureAccountingReverseAccess::class));
        $source = file_get_contents(app_path('Http/Middleware/EnsureAccountingReverseAccess.php'));
        $this->assertStringContainsString('canReverse(', $source);
    }

    public function test_lock_manage_middleware_exists(): void
    {
        $this->assertTrue(class_exists(\App\Http\Middleware\EnsureAccountingLockManageAccess::class));
        $source = file_get_contents(app_path('Http/Middleware/EnsureAccountingLockManageAccess.php'));
        $this->assertStringContainsString('canManageLocks(', $source);
    }

    public function test_routes_use_fine_grained_post_middleware(): void
    {
        $source = file_get_contents(base_path('modules/Core/routes/api.php'));

        // Journal entry posting should use EnsureAccountingPostAccess
        $this->assertStringContainsString("Route::post('/journal-entries/{id}/post'", $source);
        $this->assertStringContainsString('EnsureAccountingPostAccess', $source);
    }

    public function test_routes_use_fine_grained_reverse_middleware(): void
    {
        $source = file_get_contents(base_path('modules/Core/routes/api.php'));

        // Journal entry reversal should use EnsureAccountingReverseAccess
        $this->assertStringContainsString("Route::post('/journal-entries/{id}/reverse'", $source);
        $this->assertStringContainsString('EnsureAccountingReverseAccess', $source);
    }

    public function test_routes_use_lock_manage_middleware(): void
    {
        $source = file_get_contents(base_path('modules/Core/routes/api.php'));

        // Lock dates and fiscal periods should use EnsureAccountingLockManageAccess
        $this->assertStringContainsString('EnsureAccountingLockManageAccess', $source);
    }

    // ── High-Priority Fixes ─────────────────────────────────────────────────

    public function test_bank_reconciliation_verifies_balance_before_marking_lines(): void
    {
        $source = file_get_contents(app_path('Services/BankReconciliationService.php'));

        // Should check balance BEFORE marking lines as reconciled
        $this->assertStringContainsString('isBalanced', $source);
        $this->assertStringContainsString('Cannot mark lines as reconciled until reconciliation is balanced', $source);
    }

    public function test_bank_reconciliation_verifies_line_ownership(): void
    {
        $source = file_get_contents(app_path('Services/BankReconciliationService.php'));

        // Should verify lines belong to the correct bank account GL
        $this->assertStringContainsString('verifyLinesBelongToBankAccount', $source);
        $this->assertStringContainsString('Cannot reconcile', $source);
    }

    public function test_journal_entry_prevents_deletion_of_posted_entries(): void
    {
        $source = file_get_contents(app_path('Models/JournalEntry.php'));

        // Should prevent deletion of posted entries
        $this->assertStringContainsString('Cannot delete posted journal entry', $source);
        $this->assertStringContainsString('Posted entries must be reversed, not deleted', $source);
    }

    public function test_invoice_prevents_deletion_of_posted_invoices(): void
    {
        $source = file_get_contents(app_path('Models/Invoice.php'));

        // Should prevent deletion of posted invoices
        $this->assertStringContainsString('Cannot delete posted invoice', $source);
        $this->assertStringContainsString('Posted invoices must be reversed or credited, not deleted', $source);
    }

    public function test_bill_prevents_deletion_of_posted_bills(): void
    {
        $source = file_get_contents(app_path('Models/Bill.php'));

        // Should prevent deletion of posted bills
        $this->assertStringContainsString('Cannot delete posted bill', $source);
        $this->assertStringContainsString('Posted bills must be reversed or credited, not deleted', $source);
    }

    public function test_payment_prevents_deletion_of_posted_payments(): void
    {
        $source = file_get_contents(app_path('Models/Payment.php'));

        // Should prevent deletion of posted payments
        $this->assertStringContainsString('Cannot delete posted payment', $source);
        $this->assertStringContainsString('Posted payments must be reversed, not deleted', $source);
    }

    public function test_bill_payment_prevents_deletion_of_posted_payments(): void
    {
        $source = file_get_contents(app_path('Models/BillPayment.php'));

        // Should prevent deletion of posted bill payments
        $this->assertStringContainsString('Cannot delete posted bill payment', $source);
        $this->assertStringContainsString('Posted payments must be reversed, not deleted', $source);
    }

    public function test_subledger_reconciliation_uses_canonical_services(): void
    {
        $source = file_get_contents(app_path('Services/SubledgerReconciliationService.php'));

        // Should delegate to canonical AR/AP reconciliation services
        $this->assertStringContainsString('ARGLReconciliationService', $source);
        $this->assertStringContainsString('APGLReconciliationService', $source);

        // Should NOT use heuristics anymore
        $this->assertStringNotContainsString('ILIKE', $source);
        $this->assertStringNotContainsString("'%account%receivable%'", $source);
    }

    public function test_argl_reconciliation_service_exists(): void
    {
        $this->assertTrue(class_exists(\App\Services\Reconciliation\ARGLReconciliationService::class));
        $this->assertTrue(method_exists(\App\Services\Reconciliation\ARGLReconciliationService::class, 'reconcile'));
    }

    public function test_apgl_reconciliation_service_exists(): void
    {
        $this->assertTrue(class_exists(\App\Services\Reconciliation\APGLReconciliationService::class));
        $this->assertTrue(method_exists(\App\Services\Reconciliation\APGLReconciliationService::class, 'reconcile'));
    }

    public function test_report_service_validates_summary_freshness(): void
    {
        $source = file_get_contents(base_path('modules/FinancialReporting/src/Services/BaseReportService.php'));

        // Should check if summary data is fresh before using it
        $this->assertStringContainsString('isSummaryDataFresh', $source);

        // Should verify summary exists for date
        $this->assertStringContainsString('Summary data missing', $source);

        // Should verify summary line count matches journal
        $this->assertStringContainsString('Summary data incomplete', $source);

        // Should fall back to direct query when summary is stale
        $this->assertStringContainsString('falling back to direct query', $source);
    }

    // ── Webhook Security ───────────────────────────────────────────────────

    public function test_webhook_routes_use_secure_middleware(): void
    {
        $source = file_get_contents(base_path('routes/webhooks.php'));

        // Should use SecureWebhookAccess middleware
        $this->assertStringContainsString('SecureWebhookAccess', $source);

        // Should use ResolveTenantFromWebhook middleware
        $this->assertStringContainsString('ResolveTenantFromWebhook', $source);

        // Should NOT have public unprotected endpoints
        $this->assertStringNotContainsString('no authentication', $source);
        $this->assertStringNotContainsString('no tenancy middleware', $source);
    }

    public function test_secure_webhook_middleware_exists(): void
    {
        $this->assertTrue(class_exists(\App\Http\Middleware\SecureWebhookAccess::class));

        $source = file_get_contents(app_path('Http/Middleware/SecureWebhookAccess.php'));

        // Should have rate limiting
        $this->assertStringContainsString('RATE_LIMIT_PER_MINUTE', $source);
        $this->assertStringContainsString('Rate limit exceeded', $source);

        // Should have replay attack prevention
        $this->assertStringContainsString('webhook_processed', $source);
        $this->assertStringContainsString('already processed', $source);

        // Should have IP allowlisting for Stripe
        $this->assertStringContainsString('GATEWAY_IP_RANGES', $source);
    }

    public function test_resolve_tenant_from_webhook_middleware_exists(): void
    {
        $this->assertTrue(class_exists(\App\Http\Middleware\ResolveTenantFromWebhook::class));

        $source = file_get_contents(app_path('Http/Middleware/ResolveTenantFromWebhook.php'));

        // Should use cache for tenant lookups
        $this->assertStringContainsString('TENANT_CACHE_TTL', $source);
        $this->assertStringContainsString('webhook_tenant', $source);

        // Should NOT scan all tenants (the vulnerability)
        $this->assertStringNotContainsString('Tenant::all()', $source);

        // Should query the shared central lookup table
        $this->assertStringContainsString('payment_webhook_tenant_lookups', $source);
        $this->assertStringContainsString("['data']['object']['payment_intent']", $source);
        $this->assertStringContainsString("input('resource.supplementary_data.related_ids.order_id')", $source);
    }

    public function test_gateway_webhook_extractors_use_canonical_transaction_ids(): void
    {
        $stripeSource = file_get_contents(base_path('modules/PaymentGateway/src/Services/StripeGateway.php'));
        $paypalSource = file_get_contents(base_path('modules/PaymentGateway/src/Services/PayPalGateway.php'));

        $this->assertStringContainsString("\$data['payment_intent'] ?? \$data['id'] ?? null", $stripeSource);
        $this->assertStringContainsString("supplementary_data']['related_ids']['order_id']", $paypalSource);
    }

    public function test_webhook_controller_removes_legacy_tenant_scan(): void
    {
        $source = file_get_contents(base_path('modules/PaymentGateway/src/Controllers/PaymentWebhookController.php'));

        $this->assertStringContainsString('ensureTenantContext', $source);
        $this->assertStringContainsString('Tenant context not resolved', $source);
        $this->assertStringNotContainsString('Tenant::all()', $source);
        $this->assertStringNotContainsString('cross-tenant enumeration', $source);
        $this->assertStringNotContainsString('webhook_tenant_lookup', $source);
        $this->assertStringNotContainsString('DEPRECATED', $source);
    }
}
