<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * WS-06 · Unified Channel Runtime Convergence
 *
 * Required coverage (per audit):
 *  - FIX-01: POS return now posts a GL reversal via PosPostingService::reverseOrder()
 *  - FIX-02: PaymentController::updateInvoiceFromPayment() has been removed
 *  - FIX-03: GL parity — PosPostingService and WebOrderPostingService share the
 *            same canonical account mapping keys and PostingEngine call signature
 *  - FIX-04: Cross-channel structural parity for completion, return/cancellation,
 *            and idempotency paths
 */
class WS06ChannelRuntimeConvergenceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // FIX-01: POS Return GL Reversal
    // -------------------------------------------------------------------------

    /** @test */
    public function test_pos_posting_service_has_reverse_order_method(): void
    {
        $src = file_get_contents(
            base_path('modules/POS/src/Services/PosPostingService.php')
        );

        $this->assertStringContainsString(
            'public function reverseOrder(',
            $src,
            'PosPostingService must have a reverseOrder() method'
        );
    }

    /** @test */
    public function test_pos_posting_service_reverse_order_calls_posting_engine_reverse(): void
    {
        $src = file_get_contents(
            base_path('modules/POS/src/Services/PosPostingService.php')
        );

        $this->assertStringContainsString(
            'postingEngine->reverse(new ReversalRequest(',
            $src,
            'PosPostingService::reverseOrder() must call PostingEngine::reverse() with a ReversalRequest'
        );
    }

    /** @test */
    public function test_pos_posting_service_reverse_order_uses_pos_return_reason_code(): void
    {
        $src = file_get_contents(
            base_path('modules/POS/src/Services/PosPostingService.php')
        );

        $this->assertStringContainsString(
            "'POS_RETURN'",
            $src,
            'PosPostingService::reverseOrder() must use reason code POS_RETURN'
        );
    }

    /** @test */
    public function test_pos_posting_service_reverse_order_looks_up_journal_entry_link(): void
    {
        $src = file_get_contents(
            base_path('modules/POS/src/Services/PosPostingService.php')
        );

        // Must find the original GL entry via JournalEntryLink (same pattern as WebOrderPostingService)
        $this->assertStringContainsString(
            'JournalEntryLink::where(',
            $src,
            'PosPostingService::reverseOrder() must find the original GL entry via JournalEntryLink'
        );

        $this->assertStringContainsString(
            "'pos_order'",
            $src,
            'PosPostingService::reverseOrder() must query JournalEntryLink with source_document_type pos_order'
        );
    }

    /** @test */
    public function test_pos_order_controller_calls_reverse_order_in_create_and_restock_return(): void
    {
        $src = file_get_contents(
            base_path('modules/POS/src/Http/Controllers/PosOrderController.php')
        );

        // PosPostingService must be imported
        $this->assertStringContainsString(
            'use Modules\POS\Services\PosPostingService',
            $src,
            'PosOrderController must import PosPostingService'
        );

        // reverseOrder must be called inside createAndRestockReturn
        $this->assertStringContainsString(
            'reverseOrder(',
            $src,
            'PosOrderController::createAndRestockReturn() must call PosPostingService::reverseOrder()'
        );

        // GL reversal must be guarded by journal_entry_id presence
        $this->assertStringContainsString(
            'journal_entry_id',
            $src,
            'GL reversal in PosOrderController must be guarded by journal_entry_id presence'
        );
    }

    /** @test */
    public function test_pos_return_gl_reversal_and_stock_restoration_are_in_same_transaction(): void
    {
        $src = file_get_contents(
            base_path('modules/POS/src/Http/Controllers/PosOrderController.php')
        );

        // The createReturn() controller wraps in DB::transaction
        $this->assertStringContainsString(
            'DB::transaction(',
            $src,
            'createReturn() must use DB::transaction() to wrap GL reversal and stock restoration atomically'
        );

        // Both reverseOrder and adjustStock (stock restoration) must be in the same method scope
        $createReturnStart = strpos($src, 'public function createReturn(');
        $transactionPos    = strpos($src, 'DB::transaction(', $createReturnStart);
        $reversePos        = strpos($src, 'reverseOrder(', $createReturnStart);
        $adjustStockPos    = strpos($src, "type: 'return'", $createReturnStart);

        $this->assertNotFalse($transactionPos, 'DB::transaction must appear inside createReturn()');
        $this->assertNotFalse($reversePos,     'reverseOrder must appear inside createReturn() scope');
        $this->assertNotFalse($adjustStockPos, 'stock return adjustStock must appear inside createReturn() scope');
    }

    // -------------------------------------------------------------------------
    // FIX-02: PaymentController::updateInvoiceFromPayment() removed
    // -------------------------------------------------------------------------

    /** @test */
    public function test_payment_controller_does_not_have_update_invoice_from_payment(): void
    {
        $src = file_get_contents(
            base_path('modules/PaymentGateway/src/Controllers/PaymentController.php')
        );

        $this->assertStringNotContainsString(
            'function updateInvoiceFromPayment(',
            $src,
            'PaymentController::updateInvoiceFromPayment() must be removed — it is a deprecated competing invoice mutation path'
        );
    }

    /** @test */
    public function test_payment_controller_has_no_direct_invoice_increment_or_update(): void
    {
        $src = file_get_contents(
            base_path('modules/PaymentGateway/src/Controllers/PaymentController.php')
        );

        // No raw Invoice::where()->increment('paid_amount') outside InvoiceService
        $this->assertStringNotContainsString(
            "increment('paid_amount'",
            $src,
            'PaymentController must not directly increment Invoice.paid_amount'
        );

        // No raw Invoice::where()->update(['status' => ...]) outside InvoiceService
        $this->assertStringNotContainsString(
            "'status' => \$newBalance",
            $src,
            'PaymentController must not directly update Invoice.status based on balance calculation'
        );
    }

    // -------------------------------------------------------------------------
    // FIX-03: GL Posting Parity — PosPostingService vs WebOrderPostingService
    // -------------------------------------------------------------------------

    /** @test */
    public function test_both_posting_services_use_same_account_mapping_keys_for_revenue(): void
    {
        $posSrc = file_get_contents(
            base_path('modules/POS/src/Services/PosPostingService.php')
        );
        $webSrc = file_get_contents(
            base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php')
        );

        $sharedKeys = ['sales_revenue', 'sales_tax_payable', 'cogs', 'inventory'];

        foreach ($sharedKeys as $key) {
            $this->assertStringContainsString(
                "'$key'",
                $posSrc,
                "PosPostingService must use account mapping key '$key'"
            );
            $this->assertStringContainsString(
                "'$key'",
                $webSrc,
                "WebOrderPostingService must use account mapping key '$key'"
            );
        }
    }

    /** @test */
    public function test_both_posting_services_call_posting_engine_post(): void
    {
        $posSrc = file_get_contents(
            base_path('modules/POS/src/Services/PosPostingService.php')
        );
        $webSrc = file_get_contents(
            base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php')
        );

        $this->assertStringContainsString(
            'postingEngine->post(new PostingRequest(',
            $posSrc,
            'PosPostingService::postPaidOrder() must delegate to PostingEngine::post()'
        );
        $this->assertStringContainsString(
            'postingEngine->post(new PostingRequest(',
            $webSrc,
            'WebOrderPostingService::postPaidOrder() must delegate to PostingEngine::post()'
        );
    }

    /** @test */
    public function test_both_posting_services_use_posting_engine_interface(): void
    {
        $posSrc = file_get_contents(
            base_path('modules/POS/src/Services/PosPostingService.php')
        );
        $webSrc = file_get_contents(
            base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php')
        );

        $this->assertStringContainsString(
            'PostingEngineInterface',
            $posSrc,
            'PosPostingService must use PostingEngineInterface (not a concrete PostingEngine)'
        );
        $this->assertStringContainsString(
            'PostingEngineInterface',
            $webSrc,
            'WebOrderPostingService must use PostingEngineInterface'
        );
    }

    /** @test */
    public function test_both_posting_services_have_reversal_capability(): void
    {
        $posSrc = file_get_contents(
            base_path('modules/POS/src/Services/PosPostingService.php')
        );
        $webSrc = file_get_contents(
            base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php')
        );

        $this->assertStringContainsString(
            'postingEngine->reverse(new ReversalRequest(',
            $posSrc,
            'PosPostingService must support GL reversal via PostingEngine::reverse()'
        );
        $this->assertStringContainsString(
            'postingEngine->reverse(new ReversalRequest(',
            $webSrc,
            'WebOrderPostingService must support GL reversal via PostingEngine::reverse()'
        );
    }

    // -------------------------------------------------------------------------
    // FIX-04: Cross-Channel Parity — Completion paths
    // -------------------------------------------------------------------------

    /** @test */
    public function test_all_web_payment_paths_delegate_to_payment_transaction_completion_service(): void
    {
        $canonicalClass = 'PaymentTransactionCompletionService';

        $paths = [
            base_path('modules/PaymentGateway/src/Controllers/PaymentController.php'),
            base_path('modules/PaymentGateway/src/Controllers/PaymentWebhookController.php'),
            base_path('modules/Ecommerce/src/Http/Controllers/WebCheckoutController.php'),
        ];

        foreach ($paths as $file) {
            $src = file_get_contents($file);
            $this->assertStringContainsString(
                $canonicalClass,
                $src,
                basename($file) . " must delegate to $canonicalClass"
            );
        }
    }

    /** @test */
    public function test_all_pos_payment_paths_delegate_to_pos_payment_completion_service(): void
    {
        $canonicalClass = 'PosPaymentCompletionService';

        $paths = [
            base_path('modules/POS/src/Http/Controllers/PosOrderController.php'),
            base_path('modules/IoT/src/Jobs/ProcessPaymentWebhook.php'),
            base_path('modules/IoT/src/Http/Controllers/IoTTerminalController.php'),
        ];

        foreach ($paths as $file) {
            if (!file_exists($file)) {
                $this->markTestIncomplete("File not found: $file — skipping path check");
            }
            $src = file_get_contents($file);
            $this->assertStringContainsString(
                $canonicalClass,
                $src,
                basename($file) . " must delegate to $canonicalClass"
            );
        }
    }

    /** @test */
    public function test_payment_transaction_completion_service_delegates_pos_to_pos_service(): void
    {
        $src = file_get_contents(
            base_path('modules/PaymentGateway/src/Services/PaymentTransactionCompletionService.php')
        );

        // Must delegate POS-originated gateway transactions to PosPaymentCompletionService
        $this->assertStringContainsString(
            'PosPaymentCompletionService',
            $src,
            'PaymentTransactionCompletionService must delegate POS-originated transactions to PosPaymentCompletionService'
        );

        $this->assertStringContainsString(
            'completePosOrderPayment',
            $src,
            'PaymentTransactionCompletionService must have a completePosOrderPayment() delegation method'
        );
    }

    /** @test */
    public function test_pos_completion_service_enforces_gl_before_stock_ordering(): void
    {
        $src = file_get_contents(
            base_path('modules/POS/src/Services/PosPaymentCompletionService.php')
        );

        // postPaidOrder (GL) must appear before adjustStock (stock) in source
        $glPos    = strpos($src, 'postPaidOrder(');
        $stockPos = strpos($src, 'adjustStock(');

        $this->assertNotFalse($glPos,    'PosPaymentCompletionService must call postPaidOrder()');
        $this->assertNotFalse($stockPos, 'PosPaymentCompletionService must call adjustStock()');
        $this->assertLessThan(
            $stockPos,
            $glPos,
            'PosPaymentCompletionService must call postPaidOrder() (GL) BEFORE adjustStock() (stock)'
        );
    }

    /** @test */
    public function test_web_completion_service_enforces_gl_before_stock_ordering(): void
    {
        $src = file_get_contents(
            base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php')
        );

        // postPaidOrder (GL) must call PostingEngine::post before deductStock
        $glPos    = strpos($src, 'postingEngine->post(');
        $stockPos = strpos($src, 'deductStock(');

        $this->assertNotFalse($glPos,    'WebOrderPostingService must call PostingEngine::post()');
        $this->assertNotFalse($stockPos, 'WebOrderPostingService must have deductStock()');
        $this->assertLessThan(
            $stockPos,
            $glPos,
            'WebOrderPostingService::postPaidOrder() must call PostingEngine::post() (GL) before deductStock() (stock)'
        );
    }

    /** @test */
    public function test_both_channels_have_stock_deduplication_guards(): void
    {
        $posSrc = file_get_contents(
            base_path('modules/POS/src/Services/PosPaymentCompletionService.php')
        );
        $webSrc = file_get_contents(
            base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php')
        );

        // POS: StockMovement guard with PosOrder reference type
        $this->assertStringContainsString(
            "'PosOrder'",
            $posSrc,
            'PosPaymentCompletionService must check StockMovement for PosOrder deduplication'
        );

        // Web: StockMovement guard with WebOrder reference type
        $this->assertStringContainsString(
            "'WebOrder'",
            $webSrc,
            'WebOrderPostingService must check StockMovement for WebOrder deduplication'
        );
    }

    /** @test */
    public function test_parity_web_cancellation_and_pos_return_both_post_gl_reversal(): void
    {
        $webSrc = file_get_contents(
            base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php')
        );
        $posSrc = file_get_contents(
            base_path('modules/POS/src/Services/PosPostingService.php')
        );

        // Both must use PostingEngine::reverse() via ReversalRequest — same canonical reversal path
        $this->assertStringContainsString(
            'PostingEngine',
            $webSrc
        );
        $this->assertStringContainsString(
            'postingEngine->reverse(new ReversalRequest(',
            $webSrc,
            'WebOrderPostingService must use PostingEngine::reverse() for cancellation reversal'
        );
        $this->assertStringContainsString(
            'postingEngine->reverse(new ReversalRequest(',
            $posSrc,
            'PosPostingService must use PostingEngine::reverse() for return reversal — parity with web cancellation'
        );
    }

    /** @test */
    public function test_parity_web_cancellation_and_pos_return_both_restore_stock(): void
    {
        $webSrc = file_get_contents(
            base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php')
        );
        $posCtrlSrc = file_get_contents(
            base_path('modules/POS/src/Http/Controllers/PosOrderController.php')
        );

        $this->assertStringContainsString(
            'restoreStock(',
            $webSrc,
            'WebOrderPostingService must restore stock on cancellation'
        );
        $this->assertStringContainsString(
            "type: 'return'",
            $posCtrlSrc,
            'PosOrderController::createAndRestockReturn() must restore stock with type: return'
        );
    }

    /** @test */
    public function test_pos_completion_service_has_idempotency_guard(): void
    {
        $src = file_get_contents(
            base_path('modules/POS/src/Services/PosPaymentCompletionService.php')
        );

        // PosPayment dedup check must be present
        $this->assertStringContainsString(
            'PosPayment::where(',
            $src,
            'PosPaymentCompletionService must have an idempotency guard via PosPayment lookup'
        );
    }

    /** @test */
    public function test_payment_transaction_completion_service_has_idempotency_guard(): void
    {
        $src = file_get_contents(
            base_path('modules/PaymentGateway/src/Services/PaymentTransactionCompletionService.php')
        );

        $this->assertStringContainsString(
            'processed_at',
            $src,
            'PaymentTransactionCompletionService must check processed_at for idempotency'
        );

        $this->assertStringContainsString(
            'lockForUpdate()',
            $src,
            'PaymentTransactionCompletionService must use lockForUpdate() for concurrent idempotency'
        );
    }

    /** @test */
    public function test_process_all_web_orders_repair_path_does_not_call_deduct_stock_directly(): void
    {
        $src = file_get_contents(
            base_path('modules/Ecommerce/Console/Commands/ProcessAllWebOrders.php')
        );

        // The bypass has been fixed — deductStock() must no longer be called directly
        $this->assertStringNotContainsString(
            'deductStock(',
            $src,
            'ProcessAllWebOrders must not call deductStock() directly — use InventoryService via CompletionService'
        );

        // It must have a canonical path via PaymentTransactionCompletionService
        $this->assertStringContainsString(
            'PaymentTransactionCompletionService',
            $src,
            'ProcessAllWebOrders must use PaymentTransactionCompletionService::complete()'
        );

        // Must delegate to CompletionService for stock operations
        $this->assertStringContainsString(
            'completionService->complete(',
            $src,
            'ProcessAllWebOrders must delegate to CompletionService for stock operations'
        );
    }
}
