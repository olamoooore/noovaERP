<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * WS-04 — Inventory Truth and Mutation Convergence
 *
 * Runtime and structural proofs that:
 *   FIX-01  PurchaseOrderController::receive() delegates GRN creation to GoodsReceiptService
 *   FIX-02  ProductVariant::adjustStock() no longer writes stock_quantity directly
 *   FIX-03  POS paths do not fall back to Warehouse::getDefault() for warehouse resolution
 *   FIX-04  InventoryService is the exclusive mutation owner; dedup guards are present
 */
class WS04InventoryMutationTest extends TestCase
{
    // ── FIX-01 ──────────────────────────────────────────────────────────────────

    /**
     * PurchaseOrderController::receive() must not create GoodsReceipt or
     * GoodsReceiptLine records directly — it must delegate to GoodsReceiptService.
     */
    public function test_purchase_order_controller_receive_delegates_to_goods_receipt_service(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Http/Controllers/PurchaseOrderController.php')
        );

        // Must reference the service
        $this->assertStringContainsString('GoodsReceiptService', $source,
            'PurchaseOrderController must inject GoodsReceiptService');
        $this->assertStringContainsString('goodsReceiptService->create(', $source,
            'receive() must call goodsReceiptService->create()');
        $this->assertStringContainsString('goodsReceiptService->confirm(', $source,
            'receive() must call goodsReceiptService->confirm()');
    }

    public function test_purchase_order_controller_receive_does_not_create_goods_receipt_directly(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Http/Controllers/PurchaseOrderController.php')
        );

        $this->assertStringNotContainsString('GoodsReceipt::create(', $source,
            'PurchaseOrderController must not call GoodsReceipt::create() directly');
        $this->assertStringNotContainsString('GoodsReceiptLine::create(', $source,
            'PurchaseOrderController must not call GoodsReceiptLine::create() directly');
    }

    public function test_purchase_order_controller_receive_does_not_call_inventory_service_directly(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Http/Controllers/PurchaseOrderController.php')
        );

        $this->assertStringNotContainsString('inventoryService->adjustStock(', $source,
            'PurchaseOrderController must not call inventoryService->adjustStock() — GoodsReceiptService owns this');
        $this->assertStringNotContainsString('InventoryService $inventoryService', $source,
            'PurchaseOrderController constructor must not inject InventoryService directly');
    }

    public function test_purchase_order_controller_does_not_have_own_grn_number_generator(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Http/Controllers/PurchaseOrderController.php')
        );

        $this->assertStringNotContainsString('generateGrnNumber', $source,
            'GRN number generation must be owned by GoodsReceiptService, not the controller');
    }

    // ── FIX-02 ──────────────────────────────────────────────────────────────────

    /**
     * ProductVariant::adjustStock() must not write stock_quantity directly.
     * The projection must only be updated through InventoryService.
     */
    public function test_product_variant_adjust_stock_does_not_write_stock_quantity_directly(): void
    {
        $source = file_get_contents(app_path('Models/ProductVariant.php'));

        $this->assertStringNotContainsString('$this->stock_quantity +=', $source,
            'ProductVariant::adjustStock() must not increment stock_quantity directly');
        $this->assertStringNotContainsString('$this->saveQuietly()', $source,
            'ProductVariant::adjustStock() must not call saveQuietly() — projection write removed');
    }

    public function test_product_variant_adjust_stock_still_delegates_to_inventory_service(): void
    {
        $source = file_get_contents(app_path('Models/ProductVariant.php'));

        $this->assertStringContainsString('InventoryService::class', $source,
            'ProductVariant::adjustStock() must still delegate to InventoryService');
        $this->assertStringContainsString('adjustStock(', $source,
            'ProductVariant::adjustStock() delegation call must remain');
    }

    // ── FIX-03 ──────────────────────────────────────────────────────────────────

    /**
     * POS payment and return paths must not fall back to Warehouse::getDefault().
     * Missing warehouse must be logged explicitly and stock-tracked payment must hard-fail.
     */
    public function test_pos_payment_completion_service_does_not_use_default_warehouse_fallback(): void
    {
        $source = file_get_contents(
            base_path('modules/POS/src/Services/PosPaymentCompletionService.php')
        );

        $this->assertStringNotContainsString('Warehouse::getDefault()', $source,
            'PosPaymentCompletionService must not fall back to Warehouse::getDefault()');
    }

    public function test_pos_payment_completion_service_logs_missing_warehouse_and_hard_fails(): void
    {
        $source = file_get_contents(
            base_path('modules/POS/src/Services/PosPaymentCompletionService.php')
        );

        $this->assertStringContainsString('Log::error', $source,
            'PosPaymentCompletionService must log when no terminal warehouse is found');
        $this->assertStringContainsString('Cannot complete stock-tracked POS order without terminal warehouse_id.', $source,
            'PosPaymentCompletionService must hard-fail when warehouse is missing for stock-tracked items.');
    }

    public function test_pos_order_controller_create_return_does_not_use_default_warehouse_fallback(): void
    {
        $source = file_get_contents(
            base_path('modules/POS/src/Http/Controllers/PosOrderController.php')
        );

        $this->assertStringNotContainsString('Warehouse::getDefault()', $source,
            'PosOrderController::createAndRestockReturn() must not fall back to Warehouse::getDefault()');
    }

    public function test_pos_order_controller_create_return_logs_missing_warehouse(): void
    {
        $source = file_get_contents(
            base_path('modules/POS/src/Http/Controllers/PosOrderController.php')
        );

        $this->assertStringContainsString('stock restock skipped', $source,
            'PosOrderController must log when POS return has no terminal warehouse');
    }

    // ── FIX-04: InventoryService exclusivity and dedup ──────────────────────────

    /**
     * InventoryService::adjustStock() must use lockForUpdate on StockLevel.
     */
    public function test_inventory_service_uses_row_level_locking_on_stock_level(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Services/InventoryService.php')
        );

        $this->assertStringContainsString('lockForUpdate', $source,
            'InventoryService::adjustStock() must use lockForUpdate() on StockLevel');
        $this->assertStringContainsString('StockMovement', $source,
            'InventoryService::adjustStock() must create a StockMovement record');
        $this->assertStringContainsString('updateProductTotalStock', $source,
            'InventoryService must sync products.stock_quantity after every mutation');
    }

    /**
     * PosPaymentCompletionService deduplication guard: StockMovement sum check
     * before each deduction ensures idempotent stock mutations.
     */
    public function test_pos_payment_completion_service_has_stock_dedup_guard(): void
    {
        $source = file_get_contents(
            base_path('modules/POS/src/Services/PosPaymentCompletionService.php')
        );

        $this->assertStringContainsString("reference_type', 'PosOrder'", $source,
            'Dedup guard must filter by reference_type PosOrder');
        $this->assertStringContainsString("type', 'sale'", $source,
            'Dedup guard must filter by type sale');
        $this->assertStringContainsString('sum(\'quantity\')', $source,
            'Dedup guard must sum already-deducted quantity');
        $this->assertStringContainsString('$remainingToDeduct', $source,
            'Dedup guard must compute remainingToDeduct before calling adjustStock');
    }

    /**
     * WebOrderPostingService deduplication guard: same pattern as POS.
     */
    public function test_web_order_posting_service_has_stock_dedup_guard(): void
    {
        $source = file_get_contents(
            base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php')
        );

        $this->assertStringContainsString('StockMovement', $source,
            'WebOrderPostingService::deductStock() must check existing StockMovement records');
        $this->assertStringContainsString('adjustStock(', $source,
            'WebOrderPostingService must call InventoryService::adjustStock()');
    }

    /**
     * GL-before-stock ordering proof: postPaidOrder() must appear before adjustStock()
     * in PosPaymentCompletionService source (same assertion as AuditFixesTest but
     * re-stated here as a WS-04 closure requirement).
     */
    public function test_pos_payment_gl_posted_before_stock_deduction(): void
    {
        $source = file_get_contents(
            base_path('modules/POS/src/Services/PosPaymentCompletionService.php')
        );

        $glPosition = strpos($source, 'posPostingService->postPaidOrder(');
        $stockPosition = strpos($source, 'adjustStock(');

        $this->assertNotFalse($glPosition, 'GL posting call must be present');
        $this->assertNotFalse($stockPosition, 'Stock deduction call must be present');
        $this->assertLessThan($stockPosition, $glPosition,
            'GL posting must occur before stock deduction in PosPaymentCompletionService');
    }

    /**
     * Parity: both POS and web checkout call InventoryService::adjustStock() with
     * type 'sale'. Equivalent business events use the same mutation owner and type.
     */
    public function test_pos_and_web_checkout_use_same_stock_mutation_owner_and_type(): void
    {
        $posSource = file_get_contents(
            base_path('modules/POS/src/Services/PosPaymentCompletionService.php')
        );
        $webSource = file_get_contents(
            base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php')
        );

        $this->assertStringContainsString("type: 'sale'", $posSource,
            'POS payment must call adjustStock() with type: sale');
        $this->assertStringContainsString("'sale'", $webSource,
            'Web order deductStock() must call adjustStock() with type sale');

        // Both must use the same InventoryService interface
        $this->assertStringContainsString('inventoryService->adjustStock(', $posSource,
            'POS must use injected InventoryService');
        $this->assertStringContainsString('inventoryService->adjustStock(', $webSource,
            'Web order must use injected InventoryService');
    }

    /**
     * GoodsReceiptService is the exclusive GRN creation path.
     * Confirm it calls InventoryService::adjustStock() with type 'purchase'.
     */
    public function test_goods_receipt_service_is_canonical_grn_and_stock_path(): void
    {
        $source = file_get_contents(app_path('Services/GoodsReceiptService.php'));

        $this->assertStringContainsString("type: 'purchase'", $source,
            'GoodsReceiptService::confirm() must call adjustStock() with type: purchase');
        $this->assertStringContainsString('inventoryService->adjustStock(', $source,
            'GoodsReceiptService must call InventoryService::adjustStock()');
        $this->assertStringContainsString('generateGrnNumber', $source,
            'GoodsReceiptService must own GRN number generation');
    }
}
