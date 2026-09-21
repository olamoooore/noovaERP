<?php

namespace Tests\Feature;

use Tests\TenantTestCase;

/**
 * AR-001: Central Mutation Ownership
 *
 * No direct stock mutation must be possible outside InventoryService::adjustStock()
 * (delegated to by the module services). The dead InventoryPostingEngine was
 * removed under issue #108. This test suite validates the structural and
 * route-level guards that enforce the invariant.
 *
 * Evidence required (from closure checklist):
 *   - products.stock_quantity is not directly settable via fillable
 *   - Direct stock mutation HTTP routes are removed / return 405
 *   - InventoryService::reserveStock() (legacy path) throws LogicException
 *   - InventoryPostingEngine is the declared boundary class for engine-level callers
 *   - POS, Ecommerce, and Sales services call adjustStock(), not direct DB/model writes
 */
class AR001MutationOwnershipTest extends TenantTestCase
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

    public function test_stock_controller_mutation_methods_use_adjust_stock(): void
    {
        $controller = file_get_contents(
            base_path('modules/Inventory/src/Http/Controllers/StockController.php')
        );

        // Issue #106: manual movements now go through StockAdjustmentService,
        // which posts the GL valuation change AND delegates the stock mutation
        // to InventoryService::adjustStock(). The mutation-ownership invariant
        // is enforced at both layers.
        $this->assertStringContainsString('StockAdjustmentService', $controller,
            'StockController mutations must route through StockAdjustmentService.');

        $service = file_get_contents(
            base_path('modules/Inventory/src/Services/StockAdjustmentService.php')
        );

        $this->assertStringContainsString('adjustStock', $service,
            'StockAdjustmentService must route stock mutations through InventoryService::adjustStock().');

        $this->assertStringNotContainsString('StockLevel::create', $controller,
            'StockController must not directly create StockLevel records.');
        $this->assertStringNotContainsString('DB::raw', $controller,
            'StockController must not directly mutate stock via raw SQL.');
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

        // Stock deduction was moved to DeliveryOrder confirmation.
        // SalesOrderService documents this delegation and injects InventoryService.
        $this->assertStringContainsString('inventoryService', $source,
            'SalesOrderService must inject inventoryService.');
        $this->assertStringContainsString('Stock deduction is now handled by DeliveryOrder confirmation', $source,
            'SalesOrderService must document that stock deduction is delegated to DeliveryOrder.');
        $this->assertStringContainsString('InventoryService', $source,
            'SalesOrderService must reference InventoryService for stock operations.');
    }

    // ── Dead boundary class removal (issue #108) ─────────────────────────────

    public function test_dead_inventory_posting_engine_is_removed(): void
    {
        $this->assertFalse(
            class_exists(\App\Accounting\InventoryPostingEngine::class),
            'InventoryPostingEngine must stay removed — it had zero production callers (issue #108).'
        );
    }

    public function test_inventory_service_remains_the_mutation_authority(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Services/InventoryService.php')
        );

        $this->assertStringContainsString('function adjustStock', $source,
            'InventoryService::adjustStock must remain the single stock mutation path.');
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
