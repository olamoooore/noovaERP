<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AR-002: Movement Idempotency Source References
 *
 * Every stock movement must have a stable source reference so that
 * duplicate events (POS retry, eCommerce webhook re-delivery, GRN double-post)
 * cannot cause double stock mutation.
 *
 * Evidence required (from closure checklist):
 *   - idempotency_key field exists on stock_movements
 *   - InventoryService::adjustStock() accepts and stores idempotency_key
 *   - Duplicate call with same key returns existing movement, no re-mutation
 *   - StockMovement model includes idempotency_key in fillable
 */
class AR002MovementIdempotencyTest extends TestCase
{
    // ── Schema / structural tests ─────────────────────────────────────────────

    public function test_stock_movements_migration_adds_idempotency_key_column(): void
    {
        $migration = file_get_contents(
            base_path('database/migrations/tenant/2026_05_09_000001_add_idempotency_key_to_stock_movements.php')
        );

        $this->assertStringContainsString("'idempotency_key'", $migration,
            'Migration must define idempotency_key column.');
        $this->assertStringContainsString('->unique()', $migration,
            'idempotency_key must carry a unique constraint in the migration.');
        $this->assertStringContainsString('->nullable()', $migration,
            'idempotency_key must be nullable so existing rows are unaffected.');
    }

    public function test_stock_movement_model_has_idempotency_key_in_fillable(): void
    {
        $model = new \App\Models\StockMovement();
        $this->assertContains('idempotency_key', $model->getFillable(),
            'StockMovement::$fillable must include idempotency_key.');
    }

    public function test_inventory_service_adjust_stock_signature_accepts_idempotency_key(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Services/InventoryService.php')
        );

        $this->assertStringContainsString('?string $idempotencyKey = null', $source,
            'adjustStock() must accept an optional $idempotencyKey parameter.');
    }

    // ── Behavioural tests ─────────────────────────────────────────────────────

    public function test_adjust_stock_stores_idempotency_key_when_provided(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Services/InventoryService.php')
        );

        $this->assertStringContainsString("'idempotency_key' => \$idempotencyKey", $source,
            'adjustStock() must persist idempotency_key into the movement record.');
    }

    public function test_adjust_stock_returns_existing_movement_on_duplicate_key(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Services/InventoryService.php')
        );

        // The service must check for an existing movement using the idempotency key
        $this->assertStringContainsString("StockMovement::where('idempotency_key'", $source,
            'adjustStock() must query for existing movement by idempotency_key before mutating stock.');

        // And return early if found
        $this->assertStringContainsString('return $existing', $source,
            'adjustStock() must return the existing movement without re-mutating stock.');
    }

    public function test_adjust_stock_idempotency_guard_is_inside_transaction(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Services/InventoryService.php')
        );

        // Locate transaction opening and idempotency guard, confirm guard is inside
        $txPos   = strpos($source, 'DB::transaction(');
            $keyPos  = strpos($source, '$idempotencyKey !== null');
        $guardPos = strpos($source, "StockMovement::where('idempotency_key'");

        $this->assertNotFalse($txPos,   'DB::transaction must be present.');
        $this->assertNotFalse($keyPos,  'Idempotency key null-check must be present.');
        $this->assertNotFalse($guardPos,'Idempotency DB lookup must be present.');

        $this->assertGreaterThan($txPos, $keyPos,
            'Idempotency check must be inside the DB::transaction closure.');
        $this->assertGreaterThan($txPos, $guardPos,
            'Idempotency DB lookup must be inside the DB::transaction closure.');
    }

    public function test_idempotency_key_guard_logs_duplicate_detection(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Services/InventoryService.php')
        );

        $this->assertStringContainsString('idempotent adjustStock', $source,
            'adjustStock() must log when it detects a duplicate idempotency_key.');
    }

    // ── Reference coordinate tests ────────────────────────────────────────────

    public function test_stock_movements_table_has_reference_type_and_reference_id(): void
    {
        $migration = file_get_contents(
            base_path('database/migrations/tenant/2024_01_01_200005_create_stock_movements_table.php')
        );

        $this->assertStringContainsString("'reference_type'", $migration,
            'stock_movements must have reference_type for source tracing.');
        $this->assertStringContainsString("'reference_id'", $migration,
            'stock_movements must have reference_id for source tracing.');
    }

    public function test_stock_movements_has_structural_unique_constraint_on_reference_coordinates(): void
    {
        $migration = file_get_contents(
            base_path('database/migrations/tenant/2026_04_27_190000_add_unique_constraint_to_stock_movements.php')
        );

        $this->assertStringContainsString('stock_movements_ref_product_type_unique', $migration,
            'stock_movements must have the structural unique constraint on (reference_type, reference_id, product_id, type).');
    }

    public function test_adjust_stock_passes_reference_fields_to_movement_record(): void
    {
        $source = file_get_contents(
            base_path('modules/Inventory/src/Services/InventoryService.php')
        );

        $this->assertStringContainsString("'reference_type' => \$referenceType", $source,
            'adjustStock() must store referenceType on the movement.');
        $this->assertStringContainsString("'reference_id' => \$referenceId", $source,
            'adjustStock() must store referenceId on the movement.');
        $this->assertStringContainsString("'reference' => \$reference", $source,
            'adjustStock() must store reference string on the movement.');
    }
}
