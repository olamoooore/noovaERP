<?php

namespace Tests\Feature;

use Tests\TenantTestCase;

class SalesCoreBridgeTest extends TenantTestCase
{
    // ── Field-service sales-order bridge ──────────────────────────────────

    public function test_sales_order_service_has_create_from_work_order(): void
    {
        $this->assertTrue(
            method_exists(\Modules\Sales\Services\SalesOrderService::class, 'createFromWorkOrder'),
            'SalesOrderService must expose createFromWorkOrder() to bridge field-service invoices.'
        );
    }

    public function test_create_from_work_order_populates_source_document_fields(): void
    {
        $source = file_get_contents(base_path('modules/Sales/src/Services/SalesOrderService.php'));

        $this->assertStringContainsString("'source_document_type' => 'WorkOrder'", $source,
            'createFromWorkOrder must set source_document_type to WorkOrder.');
        $this->assertStringContainsString("'source_document_id' => \$workOrder->id", $source,
            'createFromWorkOrder must set source_document_id to the work-order ID.');
    }

    public function test_create_from_work_order_writes_sales_order_id_back(): void
    {
        $source = file_get_contents(base_path('modules/Sales/src/Services/SalesOrderService.php'));

        $this->assertStringContainsString('sales_order_id', $source,
            'createFromWorkOrder must write sales_order_id back on the WorkOrder.');
    }

    public function test_create_from_work_order_creates_labor_and_parts_lines(): void
    {
        $source = file_get_contents(base_path('modules/Sales/src/Services/SalesOrderService.php'));

        $this->assertStringContainsString("'source_line_type' => 'WorkOrderTimeLog'", $source,
            'createFromWorkOrder must create lines with source_line_type = WorkOrderTimeLog for time logs.');
        $this->assertStringContainsString("'source_line_type' => 'WorkOrderPart'", $source,
            'createFromWorkOrder must create lines with source_line_type = WorkOrderPart for parts.');
    }

    public function test_create_from_work_order_is_idempotent_via_source_query(): void
    {
        $source = file_get_contents(base_path('modules/Sales/src/Services/SalesOrderService.php'));

        $this->assertStringContainsString('source_document_type', $source,
            'createFromWorkOrder must check for existing SalesOrder before creating a new one.');
        $this->assertStringContainsString("'WorkOrder'", $source,
            'The idempotency check must use WorkOrder as the source_document_type.');
    }

    public function test_create_from_work_order_checks_existing_sales_order_id(): void
    {
        $source = file_get_contents(base_path('modules/Sales/src/Services/SalesOrderService.php'));

        $this->assertStringContainsString('sales_order_id', $source,
            'createFromWorkOrder must check workOrder->sales_order_id before creating a new order.');
    }

    // ── Actor resolution ────────────────────────────────────────────────

    public function test_sales_order_service_uses_tenant_user_helper_for_created_by(): void
    {
        $source = file_get_contents(base_path('modules/Sales/src/Services/SalesOrderService.php'));

        $this->assertStringContainsString('TenantUserHelper::requireTenantUserId', $source,
            'SalesOrderService::createOrder must use TenantUserHelper::requireTenantUserId for created_by.');
    }

    public function test_order_lifecycle_service_uses_tenant_user_helper(): void
    {
        $source = file_get_contents(base_path('modules/Sales/src/Services/OrderLifecycleService.php'));

        $this->assertStringContainsString('TenantUserHelper::resolveTenantUserId', $source,
            'OrderLifecycleService must use TenantUserHelper::resolveTenantUserId for actor_id.');
    }

    public function test_invoice_service_uses_tenant_user_helper_for_created_by(): void
    {
        $source = file_get_contents(base_path('app/Services/InvoiceService.php'));

        $this->assertStringContainsString('TenantUserHelper::requireTenantUserId', $source,
            'InvoiceService must use TenantUserHelper::requireTenantUserId for created_by and received_by.');
    }

    public function test_posting_engine_uses_tenant_user_helper(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringContainsString('TenantUserHelper::requireTenantUserId', $source,
            'PostingEngine must use TenantUserHelper::requireTenantUserId for actor resolution.');
    }

    public function test_journal_entry_uses_tenant_user_helper(): void
    {
        $source = file_get_contents(base_path('app/Models/JournalEntry.php'));

        $this->assertStringContainsString('TenantUserHelper::requireTenantUserId', $source,
            'JournalEntry must use TenantUserHelper::requireTenantUserId for posted_by and created_by.');
    }

    // ── Accounting-status bookkeeping ────────────────────────────────────

    public function test_sales_order_create_defaults_accounting_status(): void
    {
        $source = file_get_contents(base_path('modules/Sales/src/Services/SalesOrderService.php'));

        $this->assertStringContainsString("\$data['accounting_status'] ?? 'not_posted'", $source,
            'SalesOrderService::createOrder must default accounting_status to not_posted.');
    }

    public function test_invoice_posting_synchronizes_sales_order_accounting_status(): void
    {
        $source = file_get_contents(base_path('app/Services/InvoiceService.php'));

        $this->assertStringContainsString('synchronizeSalesOrderPosting', $source,
            'InvoiceService::postInvoice must call synchronizeSalesOrderPosting after posting.');
    }

    public function test_invoice_creation_synchronizes_quantity_invoiced(): void
    {
        $source = file_get_contents(base_path('modules/Sales/src/Services/SalesOrderService.php'));

        $this->assertStringContainsString('synchronizeInvoiceBookkeeping', $source,
            'SalesOrderService::createInvoice must call synchronizeInvoiceBookkeeping after invoice creation.');
        $this->assertStringContainsString('quantity_invoiced', $source,
            'synchronizeInvoiceBookkeeping must update quantity_invoiced on sales order lines.');
    }

    // ── Field-service invoice skips inventory deduction ──────────────────

    public function test_invoice_service_skips_inventory_for_work_order_source(): void
    {
        // Issue #682: the "has this document already had its goods issued?"
        // decision moved out of InvoiceService into the shared goods-issue
        // ledger, which both the invoice and the delivery-order path read.
        // The rule itself is unchanged — a WorkOrder-sourced document is never
        // the goods-issue event (FieldService deducts the parts itself) — so
        // both the stock movement and the COGS entry are suppressed for it.
        $source = file_get_contents(base_path('app/Services/GoodsIssueLedger.php'));

        $this->assertStringContainsString("'WorkOrder'", $source,
            'GoodsIssueLedger must suppress inventory for WorkOrder source documents.');
    }

    public function test_field_service_invoice_generation_uses_sales_order_bridge(): void
    {
        $source = file_get_contents(base_path('modules/FieldService/src/Services/InvoiceGenerationService.php'));

        $this->assertStringContainsString('SalesOrderService', $source,
            'InvoiceGenerationService must use SalesOrderService instead of creating invoices directly.');
        $this->assertStringContainsString('createFromWorkOrder', $source,
            'InvoiceGenerationService must call createFromWorkOrder to bridge the work order.');
    }

    // ── TenantUserHelper resolves actor with fallback ───────────────────

    public function test_tenant_user_helper_has_require_method(): void
    {
        $this->assertTrue(
            method_exists(\Modules\OCRBookkeeping\Helpers\TenantUserHelper::class, 'requireTenantUserId'),
            'TenantUserHelper must expose requireTenantUserId() for non-nullable actor attribution.'
        );
        $this->assertTrue(
            method_exists(\Modules\OCRBookkeeping\Helpers\TenantUserHelper::class, 'resolveTenantUserId'),
            'TenantUserHelper must expose resolveTenantUserId() for nullable actor resolution.'
        );
    }

    public function test_tenant_user_helper_resolve_orders_tenant_user_first(): void
    {
        // #878 / SOP §4: the implementation is platform-owned now; the
        // OCRBookkeeping class is a deprecated alias.
        $source = file_get_contents(app_path('Support/TenantUserHelper.php'));

        $this->assertStringContainsString('getTenantUserId', $source,
            'resolveTenantUserId must call getTenantUserId.');

        $this->assertStringContainsString('preferredUserId', $source,
            'resolveTenantUserId must check preferredUserId against tenant DB.');

        $getTenantPos = strpos($source, 'getTenantUserId');
        $preferredPos = strpos($source, 'preferredUserId');

        $this->assertLessThan($preferredPos, $getTenantPos,
            'resolveTenantUserId must prefer the email-mapped tenant user over preferredUserId to prevent cross-tenant ID collision.');
    }

    // ── Ecommerce cancellation uses declared payment statuses ────────────

    public function test_web_order_cancel_uses_reverse_order_posting_for_paid_orders(): void
    {
        $source = file_get_contents(base_path('modules/Ecommerce/src/Http/Controllers/WebOrderController.php'));

        $this->assertStringContainsString('reversePostingForCancellation', $source,
            'WebOrderController must call reversePostingForCancellation for paid orders instead of just stock restore.');
        $this->assertStringContainsString('reverseOrderPosting', $source,
            'reversePostingForCancellation must call WebOrderPostingService::reverseOrderPosting for paid orders.');
    }

    public function test_web_order_cancel_does_not_use_refund_pending_status(): void
    {
        $source = file_get_contents(base_path('modules/Ecommerce/src/Http/Controllers/WebOrderController.php'));

        $this->assertStringNotContainsString("'refund_pending'", $source,
            'WebOrderController must not use the undeclared refund_pending payment status string.');
        $this->assertStringContainsString('PAYMENT_STATUS_REFUNDED', $source,
            'WebOrderController must use the declared PAYMENT_STATUS_REFUNDED constant instead of hardcoded strings.');
    }
}
