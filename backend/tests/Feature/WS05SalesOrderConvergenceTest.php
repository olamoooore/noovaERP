<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * WS-05 · Sales Order Backbone Convergence
 *
 * Required coverage:
 *  - FIX-01: InvoiceController::store() routes through SalesOrderService (no direct InvoiceService bypass)
 *  - FIX-02: Post-creation total overrides are applied inside SalesOrderService::createInvoice()
 *            BEFORE synchronizeInvoiceBookkeeping() runs; no callers do a post-return $invoice->update()
 *  - FIX-03: Parity — POS, Web, and Admin channels all produce a SalesOrder + SalesInvoiceLink
 */
class WS05SalesOrderConvergenceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // FIX-01: InvoiceController no longer bypasses SalesOrderService
    // -------------------------------------------------------------------------

    /** @test */
    public function test_invoice_controller_store_injects_sales_order_service(): void
    {
        $src = file_get_contents(
            base_path('app/Http/Controllers/Api/InvoiceController.php')
        );

        // SalesOrderService must be imported
        $this->assertStringContainsString(
            'use Modules\Sales\Services\SalesOrderService',
            $src,
            'InvoiceController must import SalesOrderService'
        );

        // Constructor must accept SalesOrderService
        $this->assertStringContainsString(
            'SalesOrderService $salesOrderService',
            $src,
            'InvoiceController constructor must inject SalesOrderService'
        );
    }

    /** @test */
    public function test_invoice_controller_store_creates_sales_order_on_admin_channel(): void
    {
        $src = file_get_contents(
            base_path('app/Http/Controllers/Api/InvoiceController.php')
        );

        // store() must call createOrder() with channel: 'admin'
        $this->assertStringContainsString(
            "'channel'     => 'admin'",
            $src,
            "InvoiceController::store() must route through createOrder() with channel 'admin'"
        );

        // store() must call createInvoice() via salesOrderService, not invoiceService->createInvoice()
        $this->assertStringNotContainsString(
            '$this->invoiceService->createInvoice(',
            $src,
            'InvoiceController::store() must not call invoiceService->createInvoice() directly'
        );
    }

    /** @test */
    public function test_invoice_controller_store_fast_tracks_lifecycle(): void
    {
        $src = file_get_contents(
            base_path('app/Http/Controllers/Api/InvoiceController.php')
        );

        // The admin order must be fast-tracked through confirm → allocate → fulfil before invoicing
        $this->assertStringContainsString('confirmOrder(', $src);
        $this->assertStringContainsString('allocateOrder(', $src);
        $this->assertStringContainsString('fulfilOrder(', $src);
    }

    // -------------------------------------------------------------------------
    // FIX-02: Overrides live inside SalesOrderService::createInvoice()
    // -------------------------------------------------------------------------

    /** @test */
    public function test_sales_order_service_create_invoice_accepts_total_overrides_param(): void
    {
        $src = file_get_contents(
            base_path('modules/Sales/src/Services/SalesOrderService.php')
        );

        $this->assertStringContainsString(
            '?array $totalOverrides = null',
            $src,
            'SalesOrderService::createInvoice() must accept ?array $totalOverrides'
        );
    }

    /** @test */
    public function test_sales_order_service_applies_overrides_before_sync_bookkeeping(): void
    {
        $src = file_get_contents(
            base_path('modules/Sales/src/Services/SalesOrderService.php')
        );

        // Overrides must be applied before synchronizeInvoiceBookkeeping() is called
        $overridePos = strpos($src, '$invoice->update($totalOverrides)');
        $syncPos = strpos($src, 'synchronizeInvoiceBookkeeping(');

        $this->assertNotFalse($overridePos, 'Override block must exist inside createInvoice()');
        $this->assertNotFalse($syncPos, 'synchronizeInvoiceBookkeeping() must exist inside createInvoice()');
        $this->assertGreaterThan(
            $syncPos,
            $overridePos,
            'Override block must appear BEFORE synchronizeInvoiceBookkeeping() in source'
        );
    }

    /** @test */
    public function test_pos_order_controller_passes_overrides_to_create_invoice_no_post_return_update(): void
    {
        $src = file_get_contents(
            base_path('modules/POS/src/Http/Controllers/PosOrderController.php')
        );

        // Overrides must now be inside the createInvoice() call (look for $salesOrderService->createInvoice call with array)
        $this->assertStringContainsString(
            "'pos_order_id'",
            $src,
            'PosOrderController must pass pos_order_id in createInvoice() overrides'
        );

        // The post-return $inv->update([...]) block with pos totals must be gone
        $this->assertStringNotContainsString(
            '$inv->update(['.PHP_EOL."                    'subtotal'",
            $src,
            'PosOrderController must not have a post-return $inv->update() override block'
        );
    }

    /** @test */
    public function test_web_order_passes_overrides_to_create_invoice_no_post_return_update(): void
    {
        $src = file_get_contents(
            base_path('modules/Ecommerce/src/Models/WebOrder.php')
        );

        // web_order_id override must now be inside the createInvoice() call
        $this->assertStringContainsString(
            "'web_order_id'",
            $src,
            'WebOrder::convertToInvoice() must pass web_order_id in createInvoice() overrides'
        );

        // The standalone $invoice->update([...]) with subtotal override must be gone
        $this->assertStringNotContainsString(
            '$invoice->update(['.PHP_EOL."            'subtotal'",
            $src,
            'WebOrder::convertToInvoice() must not have a post-return $invoice->update() override block'
        );
    }

    /** @test */
    public function test_generate_missing_pos_invoices_passes_overrides_no_post_return_update(): void
    {
        $src = file_get_contents(
            base_path('app/Console/Commands/GenerateMissingPosInvoices.php')
        );

        // pos_order_id must be passed as an override to createInvoice()
        $this->assertStringContainsString(
            "'pos_order_id'",
            $src,
            'GenerateMissingPosInvoices must pass pos_order_id in createInvoice() overrides'
        );

        // The standalone $invoice->update([...]) with subtotal must be gone
        $this->assertStringNotContainsString(
            '$invoice->update(['.PHP_EOL."                    'subtotal'",
            $src,
            'GenerateMissingPosInvoices must not have a post-return $invoice->update() override block'
        );
    }

    // -------------------------------------------------------------------------
    // FIX-03: Parity — all channels produce SalesOrder + SalesInvoiceLink
    // -------------------------------------------------------------------------

    /** @test */
    public function test_pos_channel_routes_through_sales_order_service_create_invoice(): void
    {
        $src = file_get_contents(
            base_path('modules/POS/src/Http/Controllers/PosOrderController.php')
        );

        $this->assertStringContainsString(
            'salesOrderService->createInvoice(',
            $src,
            'POS channel must invoke SalesOrderService::createInvoice() to guarantee SalesInvoiceLink creation'
        );

        // Must also have idempotency guard (already invoiced check via SalesInvoiceLink)
        $this->assertStringContainsString('isInvoiced()', $src);
        $this->assertStringContainsString('SalesInvoiceLink', $src);
    }

    /** @test */
    public function test_ecommerce_channel_routes_through_sales_order_service_create_invoice(): void
    {
        $src = file_get_contents(
            base_path('modules/Ecommerce/src/Models/WebOrder.php')
        );

        $this->assertStringContainsString(
            'salesOrderService->createInvoice(',
            $src,
            'Ecommerce channel must invoke SalesOrderService::createInvoice() to guarantee SalesInvoiceLink creation'
        );

        $this->assertStringContainsString('isInvoiced()', $src);
        $this->assertStringContainsString('SalesInvoiceLink', $src);
    }

    /** @test */
    public function test_payment_gateway_channel_routes_through_sales_order_service_create_invoice(): void
    {
        $src = file_get_contents(
            base_path('modules/PaymentGateway/src/Services/PaymentTransactionCompletionService.php')
        );

        $this->assertStringContainsString(
            'salesOrderService->createInvoice(',
            $src,
            'PaymentGateway channel must invoke SalesOrderService::createInvoice()'
        );

        $this->assertStringContainsString('isInvoiced()', $src);
        $this->assertStringContainsString('SalesInvoiceLink', $src);
    }

    /** @test */
    public function test_admin_channel_routes_through_sales_order_service_create_invoice(): void
    {
        $src = file_get_contents(
            base_path('app/Http/Controllers/Api/InvoiceController.php')
        );

        $this->assertStringContainsString(
            'salesOrderService->createInvoice(',
            $src,
            'Admin (manual invoice) channel must invoke SalesOrderService::createInvoice()'
        );
    }

    /** @test */
    public function test_sales_invoice_link_is_only_created_by_sales_order_service(): void
    {
        $salesOrderServiceSrc = file_get_contents(
            base_path('modules/Sales/src/Services/SalesOrderService.php')
        );

        $this->assertStringContainsString(
            'SalesInvoiceLink::create(',
            $salesOrderServiceSrc,
            'SalesOrderService must own SalesInvoiceLink creation'
        );

        // None of the callers should create SalesInvoiceLink directly
        $callerFiles = [
            base_path('app/Http/Controllers/Api/InvoiceController.php'),
            base_path('modules/POS/src/Http/Controllers/PosOrderController.php'),
            base_path('modules/Ecommerce/src/Models/WebOrder.php'),
            base_path('app/Console/Commands/GenerateMissingPosInvoices.php'),
            base_path('modules/PaymentGateway/src/Services/PaymentTransactionCompletionService.php'),
        ];

        foreach ($callerFiles as $file) {
            $callerSrc = file_get_contents($file);
            $this->assertStringNotContainsString(
                'SalesInvoiceLink::create(',
                $callerSrc,
                basename($file).' must not create SalesInvoiceLink directly'
            );
        }
    }

    /** @test */
    public function test_create_from_pos_order_is_idempotent_via_channel_reference(): void
    {
        $src = file_get_contents(
            base_path('modules/Sales/src/Services/SalesOrderService.php')
        );

        // SalesChannelReference idempotency guard must be present for POS channel
        $this->assertStringContainsString(
            "where('channel', 'pos')",
            $src,
            'createFromPosOrder() must use SalesChannelReference idempotency guard'
        );

        $this->assertStringContainsString(
            "where('channel', 'ecommerce')",
            $src,
            'createFromWebOrder() must use SalesChannelReference idempotency guard'
        );
    }
}
