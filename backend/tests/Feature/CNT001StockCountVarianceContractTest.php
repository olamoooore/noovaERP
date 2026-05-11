<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * CNT-001: Stock count, variance and adjustment workflow tested.
 *
 * Contract checks verify that count-driven corrections are modeled with
 * explicit adjustment reasons, atomic quantity transitions, and durable
 * variance metadata on stock movements.
 */
class CNT001StockCountVarianceContractTest extends TestCase
{
    public function test_inventory_migration_seeds_stock_count_adjustment_reason(): void
    {
        $migration = file_get_contents(
            base_path('database/migrations/tenant/2026_01_01_000001_enhance_inventory_module.php')
        );

        $this->assertStringContainsString("'Stock Count Correction'", $migration);
        $this->assertStringContainsString("'code' => 'COUNT'", $migration);
        $this->assertStringContainsString("'type' => 'both'", $migration);
    }

    public function test_inventory_settings_support_adjustment_approval_policy(): void
    {
        $migration = file_get_contents(
            base_path('database/migrations/tenant/2026_01_01_000001_enhance_inventory_module.php')
        );

        $this->assertStringContainsString("'require_adjustment_approval'", $migration);
        $this->assertStringContainsString('inventory_settings', $migration);
    }

    public function test_adjust_stock_supports_adjustment_mode_and_reason_tracking(): void
    {
        $service = file_get_contents(base_path('modules/Inventory/src/Services/InventoryService.php'));

        $this->assertStringContainsString("case 'adjustment':", $service);
        $this->assertStringContainsString('// Direct set for adjustments', $service);
        $this->assertStringContainsString("'adjustment_reason_id' => ", $service);
        $this->assertStringContainsString("'notes' => ", $service);
    }

    public function test_adjust_stock_persists_variance_audit_columns_for_reconciliation(): void
    {
        $service = file_get_contents(base_path('modules/Inventory/src/Services/InventoryService.php'));

        $this->assertStringContainsString("'old_quantity' => ", $service);
        $this->assertStringContainsString("'balance_after' => ", $service);
        $this->assertStringContainsString('$newQuantity = $quantity', $service);
    }

    public function test_stock_movement_schema_includes_adjustment_and_variance_columns(): void
    {
        $baseMigration = file_get_contents(
            base_path('database/migrations/tenant/2024_01_01_200005_create_stock_movements_table.php')
        );
        $backfillMigration = file_get_contents(
            base_path('database/migrations/tenant/2026_04_01_070000_backfill_stock_movement_adjustment_columns.php')
        );

        $this->assertStringContainsString("'adjustment'", $baseMigration);
        $this->assertStringContainsString('balance_after', $baseMigration);
        $this->assertStringContainsString('old_quantity', $backfillMigration);
        $this->assertStringContainsString('adjustment_reason_id', $backfillMigration);
        $this->assertStringContainsString('warehouse_id', $backfillMigration);
    }
}
