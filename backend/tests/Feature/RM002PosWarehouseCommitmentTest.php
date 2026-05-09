<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * RM-002 extension: POS warehouse commitment model
 *
 * Stock-tracked POS completion/backfill must require an explicit terminal warehouse
 * and must never silently skip stock mutation.
 */
class RM002PosWarehouseCommitmentTest extends TestCase
{
    public function test_pos_completion_flow_requires_terminal_warehouse_before_posting(): void
    {
        $source = file_get_contents(
            base_path('modules/POS/src/Services/PosPaymentCompletionService.php')
        );

        $resolvePos = strpos($source, 'requireWarehouseId($posOrder, \'complete\')');
        $postPos = strpos($source, 'posPostingService->postPaidOrder(');

        $this->assertNotFalse($resolvePos,
            'POS complete flow must resolve/require warehouse when stock-tracked items exist.');
        $this->assertNotFalse($postPos,
            'POS complete flow must still post GL via PosPostingService.');
        $this->assertLessThan($postPos, $resolvePos,
            'Warehouse commitment must happen before GL posting so missing warehouse fails fast.');
    }

    public function test_pos_backfill_flow_requires_terminal_warehouse_before_posting(): void
    {
        $source = file_get_contents(
            base_path('modules/POS/src/Services/PosPaymentCompletionService.php')
        );

        $resolvePos = strpos($source, 'requireWarehouseId($posOrder, \'backfill\')');
        $postPos = strpos($source, 'posPostingService->postPaidOrder($posOrder, $posOrder->total, $paymentMethod)');

        $this->assertNotFalse($resolvePos,
            'POS backfill flow must resolve/require warehouse when stock-tracked items exist.');
        $this->assertNotFalse($postPos,
            'POS backfill flow must still call PosPostingService when posting is missing.');
        $this->assertLessThan($postPos, $resolvePos,
            'Backfill must fail fast on missing warehouse before attempting posting side effects.');
    }

    public function test_missing_warehouse_is_logged_as_error_not_warning(): void
    {
        $source = file_get_contents(
            base_path('modules/POS/src/Services/PosPaymentCompletionService.php')
        );

        $this->assertStringContainsString('Log::error', $source,
            'Missing warehouse must be logged as error.');
        $this->assertStringNotContainsString('stock deduction skipped', $source,
            'Silent skip language must be removed for stock-tracked POS completion.');
    }

    public function test_missing_warehouse_throws_runtime_exception(): void
    {
        $source = file_get_contents(
            base_path('modules/POS/src/Services/PosPaymentCompletionService.php')
        );

        $this->assertStringContainsString('throw new \\RuntimeException(', $source,
            'Missing terminal warehouse must throw RuntimeException for stock-tracked items.');
        $this->assertStringContainsString('Cannot complete stock-tracked POS order without terminal warehouse_id.', $source,
            'Exception message must clearly describe the warehouse commitment invariant.');
    }

    public function test_service_has_stock_tracked_item_guard(): void
    {
        $source = file_get_contents(
            base_path('modules/POS/src/Services/PosPaymentCompletionService.php')
        );

        $this->assertStringContainsString('hasStockTrackedItems', $source,
            'Service must determine whether warehouse commitment is required per order.');
        $this->assertStringContainsString('track_inventory', $source,
            'Stock tracking flag must drive the warehouse requirement.');
    }

    public function test_pos_service_still_uses_canonical_inventory_mutation_path(): void
    {
        $source = file_get_contents(
            base_path('modules/POS/src/Services/PosPaymentCompletionService.php')
        );

        $this->assertStringContainsString('inventoryService->adjustStock(', $source,
            'POS completion must still mutate stock via InventoryService::adjustStock().');
        $this->assertStringContainsString("referenceType: 'PosOrder'", $source,
            'POS movement referenceType must remain PosOrder for traceability.');
    }
}
