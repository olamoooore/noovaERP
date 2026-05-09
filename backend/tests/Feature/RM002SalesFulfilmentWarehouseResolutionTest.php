<?php

namespace Tests\Feature;

use Tests\TestCase;

class RM002SalesFulfilmentWarehouseResolutionTest extends TestCase
{
    public function test_sales_fulfilment_does_not_use_default_warehouse_fallback(): void
    {
        $source = file_get_contents(
            base_path('modules/Sales/src/Services/SalesOrderService.php')
        );

        $this->assertStringNotContainsString('Warehouse::getDefault()', $source,
            'Sales fulfilment must not fall back to Warehouse::getDefault().');
    }

    public function test_sales_fulfilment_requires_explicit_warehouse_for_stock_tracked_lines(): void
    {
        $source = file_get_contents(
            base_path('modules/Sales/src/Services/SalesOrderService.php')
        );

        $this->assertStringContainsString('resolveFulfilmentWarehouseId', $source,
            'Sales fulfilment must resolve warehouse through explicit resolver.');
        $this->assertStringContainsString('Cannot fulfil stock-tracked sales order without explicit warehouse_id.', $source,
            'Sales fulfilment must hard-fail when warehouse is missing.');
        $this->assertStringContainsString('Log::error', $source,
            'Missing warehouse path must be logged for audit visibility.');
    }

    public function test_sales_status_mutation_occurs_after_inventory_posting_callsite(): void
    {
        $source = file_get_contents(
            base_path('modules/Sales/src/Services/SalesOrderService.php')
        );

        $stockPosition = strpos($source, 'inventoryService->adjustStock(');
        $statusPosition = strpos($source, 'lifecycleService->fulfil(');

        $this->assertNotFalse($stockPosition, 'Stock posting call must be present.');
        $this->assertNotFalse($statusPosition, 'Fulfil status mutation call must be present.');
        $this->assertLessThan($statusPosition, $stockPosition,
            'Inventory posting must occur before fulfilment status mutation.');
    }
}
