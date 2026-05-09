<?php

namespace Tests\Feature;

use Tests\TestCase;

class LEG001LegacyReservationClosureTest extends TestCase
{
    public function test_inventory_service_legacy_reserve_stock_method_is_closed(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Services/InventoryService.php')
        );

        $this->assertStringContainsString('function reserveStock', $source,
            'InventoryService::reserveStock method must still be explicit for compatibility handling.');
        $this->assertStringContainsString('throw new \\LogicException', $source,
            'Legacy reserveStock path must throw and be unusable as cache-only reservation.');
        $this->assertStringContainsString('StockReservationService::reserve()', $source,
            'Legacy reserveStock error must direct callers to StockReservationService::reserve().');
    }

    public function test_inventory_service_legacy_reserve_stock_no_longer_mutates_reserved_quantity(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Services/InventoryService.php')
        );

        $this->assertStringNotContainsString('reserved_quantity +=', $source,
            'Legacy reserveStock path must not mutate reserved_quantity directly.');
    }
}
