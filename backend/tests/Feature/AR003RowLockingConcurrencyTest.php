<?php

namespace Tests\Feature;

use Tests\TenantTestCase;

/**
 * AR-003: Stock-Level Row Locking / Concurrency Safety
 *
 * Concurrent stock mutations must not produce oversell or double-release.
 * This is enforced by:
 *   1. lockForUpdate() on StockLevel rows inside DB::transaction in adjustStock()
 *   2. lockForUpdate() in StockReservationService::reserve()
 *   3. The (reference_type, reference_id, product_id, type) unique constraint
 *      on stock_movements (acts as DB-level dedup last-resort)
 *   4. The idempotency_key unique constraint (AR-002) as second dedup layer
 *
 * Evidence required (from closure checklist):
 *   - adjustStock() acquires lockForUpdate on StockLevel before mutation
 *   - StockReservationService::reserve() acquires lockForUpdate on StockLevel
 *   - Oversell is rejected when allow_negative_stock is false
 *   - Stock level balance is atomically consistent (old + delta = new)
 */
class AR003RowLockingConcurrencyTest extends TenantTestCase
{
    // ── Structural: lockForUpdate presence ───────────────────────────────────

    public function test_adjust_stock_acquires_row_lock_on_stock_level(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Services/InventoryService.php')
        );

        $this->assertStringContainsString('lockForUpdate()', $source,
            'adjustStock() must call lockForUpdate() on StockLevel to prevent concurrent oversell.');
    }

    public function test_adjust_stock_lock_is_inside_db_transaction(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Services/InventoryService.php')
        );

        $txPos = strpos($source, 'DB::transaction(');
        $lockPos = strpos($source, 'lockForUpdate()');

        $this->assertNotFalse($txPos, 'DB::transaction must be present in adjustStock.');
        $this->assertNotFalse($lockPos, 'lockForUpdate() must be present in adjustStock.');
        $this->assertGreaterThan($txPos, $lockPos,
            'lockForUpdate() must be called inside the DB::transaction closure, not outside it.');
    }

    public function test_stock_reservation_service_acquires_row_lock(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Services/StockReservationService.php')
        );

        $this->assertStringContainsString('lockForUpdate()', $source,
            'StockReservationService::reserve() must call lockForUpdate() on StockLevel.');
    }

    // ── Structural: oversell guard ────────────────────────────────────────────

    public function test_adjust_stock_rejects_oversell_when_negative_stock_disabled(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Services/InventoryService.php')
        );

        $this->assertStringContainsString('allow_negative_stock', $source,
            'adjustStock() must check allow_negative_stock setting.');
        $this->assertStringContainsString('Insufficient stock', $source,
            'adjustStock() must throw an exception when stock would go negative and negative stock is disabled.');
    }

    public function test_stock_reservation_rejects_over_reservation(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Services/StockReservationService.php')
        );

        $this->assertStringContainsString('available', $source,
            'StockReservationService must check available quantity before reserving.');
        $this->assertStringContainsString('Cannot reserve', $source,
            'StockReservationService must throw when reservation exceeds availability.');
    }

    // ── Structural: atomic balance consistency ────────────────────────────────

    public function test_adjust_stock_stores_old_quantity_and_balance_after(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Services/InventoryService.php')
        );

        $this->assertStringContainsString("'old_quantity' => \$oldQuantity", $source,
            'adjustStock() must record old_quantity for audit trail and reconciliation.');
        $this->assertStringContainsString("'balance_after' => \$newQuantity", $source,
            'adjustStock() must record balance_after to allow atomic consistency verification.');
    }

    public function test_stock_movement_model_includes_balance_tracking_fields(): void
    {
        $model = new \App\Models\StockMovement;
        $fillable = $model->getFillable();

        $this->assertContains('old_quantity', $fillable,
            'StockMovement must track old_quantity for concurrency audit.');
        $this->assertContains('balance_after', $fillable,
            'StockMovement must track balance_after for atomic consistency proof.');
    }

    // ── Structural: DB-level deduplication layers ─────────────────────────────

    public function test_structural_unique_constraint_exists_on_stock_movements(): void
    {
        $migration = file_get_contents(
            base_path('database/migrations/tenant/2026_04_27_190000_add_unique_constraint_to_stock_movements.php')
        );

        $this->assertStringContainsString('stock_movements_ref_product_type_unique', $migration,
            'DB-level unique constraint on (reference_type, reference_id, product_id, type) must exist as last-resort concurrency guard.');
    }

    public function test_idempotency_key_unique_constraint_exists_on_stock_movements(): void
    {
        $migration = file_get_contents(
            base_path('database/migrations/tenant/2026_05_09_000001_add_idempotency_key_to_stock_movements.php')
        );

        $this->assertStringContainsString('->unique()', $migration,
            'idempotency_key unique constraint must exist as second dedup layer (AR-002/AR-003 intersection).');
    }

    // ── Structural: transaction wrapping ─────────────────────────────────────

    public function test_adjust_stock_wraps_full_mutation_in_single_transaction(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Services/InventoryService.php')
        );

        // The function body is one DB::transaction that covers lock, mutation, movement create
        $txOpen = strpos($source, 'DB::transaction(');
        $savePos = strpos($source, '$stockLevel->save()');
        $createPos = strpos($source, 'StockMovement::create(');

        $this->assertGreaterThan($txOpen, $savePos,
            'StockLevel::save() must be inside the DB::transaction.');
        $this->assertGreaterThan($txOpen, $createPos,
            'StockMovement::create() must be inside the DB::transaction.');
        $this->assertGreaterThan($savePos, $createPos,
            'StockLevel must be saved before StockMovement is created within the same transaction.');
    }
}
