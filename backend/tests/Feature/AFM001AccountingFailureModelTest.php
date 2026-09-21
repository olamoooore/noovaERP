<?php

namespace Tests\Feature;

use Tests\TenantTestCase;

/**
 * AFM-001: Accounting Failure Model proof tests.
 *
 * Required closure evidence:
 * - POS/Web checkout flows use atomic model: GL + stock are wrapped together.
 * - The dead InventoryPostingEngine "deferred" boundary was removed (issue
 *   #108): its accounting dispatch pointed at a job that never existed, so it
 *   documented a model the system does not implement.
 */
class AFM001AccountingFailureModelTest extends TenantTestCase
{
    public function test_pos_completion_flow_is_atomic_gl_then_stock_in_single_transaction(): void
    {
        $source = file_get_contents(
            base_path('modules/POS/src/Services/PosPaymentCompletionService.php')
        );

        $txPos = strpos($source, 'DB::transaction(');
        $glPos = strpos($source, 'posPostingService->postPaidOrder(');
        $stockPos = strpos($source, 'inventoryService->adjustStock(');

        $this->assertNotFalse($txPos, 'POS completion must use DB::transaction().');
        $this->assertNotFalse($glPos, 'POS completion must post GL using PosPostingService.');
        $this->assertNotFalse($stockPos, 'POS completion must mutate stock using InventoryService.');

        $this->assertGreaterThan($txPos, $glPos,
            'POS GL posting must occur inside the transaction.');
        $this->assertGreaterThan($txPos, $stockPos,
            'POS stock mutation must occur inside the transaction.');
        $this->assertLessThan($stockPos, $glPos,
            'POS flow must post GL before stock mutation to keep failure model deterministic.');
    }

    public function test_pos_posting_service_fails_closed_when_gl_posting_fails(): void
    {
        $source = file_get_contents(
            base_path('modules/POS/src/Services/PosPostingService.php')
        );

        $this->assertStringContainsString('if (!$result->success)', $source,
            'POS posting service must check posting result success flag.');
        $this->assertStringContainsString('throw new \\RuntimeException("POS posting failed:', $source,
            'POS posting service must throw when GL posting fails (no silent continuation).');
    }

    public function test_web_order_posting_is_atomic_gl_then_stock_in_single_transaction(): void
    {
        $source = file_get_contents(
            base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php')
        );

        $txPos = strpos($source, 'return DB::transaction(function () use ($order, $totalPaid, $paymentMethod)');
        $glPos = strpos($source, 'postingEngine->post(new PostingRequest(');
        $stockPos = strpos($source, '$this->deductStock($order);');

        $this->assertNotFalse($txPos, 'Web posting must use DB::transaction().');
        $this->assertNotFalse($glPos, 'Web posting must call PostingEngine::post().');
        $this->assertNotFalse($stockPos, 'Web posting must call deductStock().');

        $this->assertGreaterThan($txPos, $glPos,
            'Web GL posting must be inside the transaction.');
        $this->assertGreaterThan($txPos, $stockPos,
            'Web stock deduction must be inside the transaction.');
        $this->assertLessThan($stockPos, $glPos,
            'Web flow must execute GL posting before stock deduction in the same transaction.');
    }

    public function test_web_order_posting_throws_when_gl_fails_before_stock_mutation(): void
    {
        $source = file_get_contents(
            base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php')
        );

        $this->assertStringContainsString('if (!$result->success)', $source,
            'Web posting service must inspect posting result success flag.');
        $this->assertStringContainsString('throw new \\RuntimeException(', $source,
            'Web posting service must throw on GL posting failure.');
        $this->assertStringContainsString('GL posting failed for web order', $source,
            'Web posting failure must be explicit and fail-closed.');
    }

    public function test_web_order_reversal_is_atomic_reversal_then_stock_restore(): void
    {
        $source = file_get_contents(
            base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php')
        );

        $txPos = strpos($source, 'return DB::transaction(function () use ($order, $link, $reason)');
        $reversePos = strpos($source, 'postingEngine->reverse(new ReversalRequest(');
        $restorePos = strpos($source, '$this->restoreStock($order);');

        $this->assertNotFalse($txPos, 'Web reversal must use DB::transaction().');
        $this->assertNotFalse($reversePos, 'Web reversal must call PostingEngine::reverse().');
        $this->assertNotFalse($restorePos, 'Web reversal must call restoreStock().');

        $this->assertGreaterThan($txPos, $reversePos,
            'GL reversal must occur inside the transaction.');
        $this->assertGreaterThan($txPos, $restorePos,
            'Stock restoration must occur inside the transaction.');
        $this->assertLessThan($restorePos, $reversePos,
            'GL reversal must happen before stock restoration within the same transaction.');
    }

    public function test_dead_inventory_posting_engine_boundary_is_removed(): void
    {
        $this->assertFalse(
            class_exists(\App\Accounting\InventoryPostingEngine::class),
            'The dead InventoryPostingEngine boundary must stay removed (issue #108).'
        );
    }
}
