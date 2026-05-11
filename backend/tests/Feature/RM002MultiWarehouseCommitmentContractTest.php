<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * RM-002: Warehouse mandatory at earliest stock commitment point.
 */
class RM002MultiWarehouseCommitmentContractTest extends TestCase
{
    public function test_sales_orders_schema_supports_explicit_warehouse_commitment(): void
    {
        $migration = file_get_contents(
            base_path('database/migrations/tenant/2026_05_11_100001_add_warehouse_id_to_sales_orders.php')
        );

        $this->assertStringContainsString("Schema::hasColumn('sales_orders', 'warehouse_id')", $migration);
        $this->assertStringContainsString("->foreignId('warehouse_id')", $migration);
        $this->assertStringContainsString("->constrained('warehouses')", $migration);
    }

    public function test_sales_order_allocation_requires_warehouse_before_status_transition_for_stock_tracked_lines(): void
    {
        $service = file_get_contents(base_path('modules/Sales/src/Services/SalesOrderService.php'));

        $guardPos = strpos($service, 'requireCommitmentWarehouseId($order, \'allocate\')');
        $allocatePos = strpos($service, '$this->lifecycleService->allocate($order, \'Stock allocated\')');

        $this->assertNotFalse($guardPos);
        $this->assertNotFalse($allocatePos);
        $this->assertLessThan($allocatePos, $guardPos);
        $this->assertStringContainsString('Cannot {$phase} stock-tracked sales order without explicit warehouse_id.', $service);
    }

    public function test_sales_order_fulfilment_reuses_commitment_warehouse_guard(): void
    {
        $service = file_get_contents(base_path('modules/Sales/src/Services/SalesOrderService.php'));

        $guardPos = strpos($service, "requireCommitmentWarehouseId(\$order, 'fulfil')");
        $adjustPos = strpos($service, '$this->inventoryService->adjustStock(');

        $this->assertNotFalse($guardPos);
        $this->assertNotFalse($adjustPos);
        $this->assertLessThan($adjustPos, $guardPos);
    }
}
