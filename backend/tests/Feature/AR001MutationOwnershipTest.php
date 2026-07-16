<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AR-001: Central Mutation Ownership
 *
 * No direct stock mutation must be possible outside InventoryService::adjustStock()
 * or the InventoryPostingEngine.  This test suite validates the structural
 * and route-level guards that enforce the invariant.
 *
 * Evidence required (from closure checklist):
 *   - products.stock_quantity is not directly settable via fillable
 *   - Direct stock mutation HTTP routes are removed / return 405
 *   - InventoryService::reserveStock() (legacy path) throws LogicException
 *   - InventoryPostingEngine is the declared boundary class for engine-level callers
 *   - POS, Ecommerce, and Sales services call adjustStock(), not direct DB/model writes
 */
class AR001MutationOwnershipTest extends TestCase
{
    // ── Product model guards ──────────────────────────────────────────────────

    public function test_product_stock_quantity_is_not_in_fillable(): void
    {
        $model = new \App\Models\Product;
        $this->assertNotContains('stock_quantity', $model->getFillable(),
            'products.stock_quantity must be excluded from fillable — mutations must go through InventoryService.');
    }

    public function test_product_model_documents_mutation_restriction(): void
    {
        $source = file_get_contents(base_path('app/Models/Product.php'));
        $this->assertStringContainsString('stock_quantity', $source,
            'Product.php must still reference stock_quantity (as a computed column).');
        $this->assertStringContainsString('InventoryService', $source,
            'Product.php must document that stock mutations must go through InventoryService.');
    }

    // ── Route / endpoint guards ───────────────────────────────────────────────

    public function test_direct_stock_in_route_is_removed(): void
    {
        $routes = file_get_contents(base_path('modules/Inventory/routes/api.php'));
        // Route should not map /stock/in to a POST handler
        $this->assertStringNotContainsString("'stock/in'", $routes,
            'Direct /stock/in route must not exist.');
        $this->assertStringNotContainsString('"stock/in"', $routes,
            'Direct /stock/in route must not exist.');
    }

    public function test_direct_stock_out_route_is_removed(): void
    {
        $routes = file_get_contents(base_path('modules/Inventory/routes/api.php'));
        $this->assertStringNotContainsString("'stock/out'", $routes,
            'Direct /stock/out route must not exist.');
        $this->assertStringNotContainsString('"stock/out"', $routes,
            'Direct /stock/out route must not exist.');
    }

    public function test_direct_stock_adjust_route_is_removed(): void
    {
        $routes = file_get_contents(base_path('modules/Inventory/routes/api.php'));
        $this->assertStringNotContainsString("'stock/adjust'", $routes,
            'Direct /stock/adjust route must not exist.');
        $this->assertStringNotContainsString('"stock/adjust"', $routes,
            'Direct /stock/adjust route must not exist.');
    }

    public function test_stock_controller_mutation_methods_return_disabled_message(): void
    {
        $controller = file_get_contents(
            base_path('modules/Inventory/src/Http/Controllers/StockController.php')
        );

        $this->assertStringContainsString('Direct stock-in mutations are disabled', $controller,
            'StockController::stockIn() must return a disabled message.');
        $this->assertStringContainsString('Direct stock-out mutations are disabled', $controller,
            'StockController::stockOut() must return a disabled message.');
        $this->assertStringContainsString('Direct stock adjustments are disabled', $controller,
            'StockController::adjust() must return a disabled message.');
    }

    // ── Legacy API closure (LEG-001 crosscheck) ───────────────────────────────

    public function test_legacy_inventory_service_reserve_stock_is_closed(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Services/InventoryService.php')
        );

        $this->assertStringContainsString('reserveStock', $source,
            'reserveStock method must still exist as a closed stub.');
        $this->assertStringContainsString('LogicException', $source,
            'reserveStock must throw LogicException (LEG-001 closure).');
        $this->assertStringContainsString('StockReservationService', $source,
            'reserveStock must redirect callers to StockReservationService.');
    }

    // ── Caller compliance: POS ────────────────────────────────────────────────

    public function test_pos_payment_completion_service_uses_inventory_service_adjust_stock(): void
    {
        $source = file_get_contents(
            base_path('modules/POS/src/Services/PosPaymentCompletionService.php')
        );

        $this->assertStringContainsString('inventoryService', $source,
            'PosPaymentCompletionService must reference inventoryService.');
        $this->assertStringContainsString('adjustStock', $source,
            'PosPaymentCompletionService must call adjustStock() for stock mutations.');
    }

    // ── Caller compliance: Ecommerce ──────────────────────────────────────────

    public function test_web_order_posting_service_uses_inventory_service_adjust_stock(): void
    {
        $source = file_get_contents(
            base_path('modules/Ecommerce/src/Services/WebOrderPostingService.php')
        );

        $this->assertStringContainsString('adjustStock', $source,
            'WebOrderPostingService must call adjustStock() and not directly mutate stock.');
    }

    // ── Caller compliance: Sales ──────────────────────────────────────────────

    public function test_sales_order_service_uses_inventory_service_adjust_stock(): void
    {
        $source = file_get_contents(
            base_path('modules/Sales/src/Services/SalesOrderService.php')
        );

        $this->assertStringContainsString('adjustStock', $source,
            'SalesOrderService must call adjustStock() for stock mutations.');
    }

    // ── InventoryPostingEngine boundary ──────────────────────────────────────

    public function test_inventory_posting_engine_class_exists(): void
    {
        $this->assertTrue(
            class_exists(\App\Accounting\InventoryPostingEngine::class),
            'InventoryPostingEngine class must exist as the declared engine-level mutation boundary.'
        );
    }

    public function test_inventory_posting_engine_delegates_to_inventory_service(): void
    {
        $source = file_get_contents(
            base_path('app/Accounting/InventoryPostingEngine.php')
        );

        $this->assertStringContainsString('InventoryService', $source,
            'InventoryPostingEngine must delegate stock mutations to InventoryService.');
        $this->assertStringContainsString('adjustStock', $source,
            'InventoryPostingEngine must call adjustStock() for all stock mutations.');
    }

    public function test_accounting_posting_engine_does_not_directly_mutate_stock(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringNotContainsString('StockLevel::', $source,
            'AccountingPostingEngine (PostingEngine) must not directly mutate StockLevel.');
        $this->assertStringNotContainsString('StockMovement::create', $source,
            'AccountingPostingEngine must not directly create StockMovement records.');
        $this->assertStringNotContainsString('adjustStock', $source,
            'AccountingPostingEngine must not call adjustStock() — that is InventoryPostingEngine\'s domain.');
    }
}
