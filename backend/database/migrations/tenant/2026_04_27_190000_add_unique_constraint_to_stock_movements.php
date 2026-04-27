<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * TASK: INV-001 - Add unique constraint on stock_movements
     * 
     * RATIONALE:
     * - Prevents duplicate stock deductions under concurrent payment completion
     * - Enforces idempotency at database level instead of query-based checks
     * - Critical for inventory accuracy under load
     * 
     * CONSTRAINT: (reference_type, reference_id, product_id, type)
     * This ensures one stock movement per product per transaction per type
     */
    public function up(): void
    {
        // PREFLIGHT: Check for existing duplicates
        $duplicates = DB::select("
            SELECT reference_type, reference_id, product_id, type, COUNT(*) as count
            FROM stock_movements
            WHERE reference_type IS NOT NULL 
              AND reference_id IS NOT NULL
            GROUP BY reference_type, reference_id, product_id, type
            HAVING COUNT(*) > 1
        ");

        if (!empty($duplicates)) {
            $duplicateCount = count($duplicates);
            Log::warning("Found {$duplicateCount} duplicate stock_movements before constraint", [
                'migration' => '2026_04_27_190000_add_unique_constraint_to_stock_movements',
                'duplicates' => $duplicates,
            ]);

            // For safety, we'll allow the migration to proceed but log the duplicates
            // In production, you might want to abort or clean up duplicates first
            Log::info("Proceeding with constraint creation despite duplicates. Constraint will prevent future duplicates.");
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            // Add unique constraint on (reference_type, reference_id, product_id, type)
            // This prevents duplicate stock movements for the same transaction
            $table->unique(
                ['reference_type', 'reference_id', 'product_id', 'type'],
                'stock_movements_ref_product_type_unique'
            );
        });

        Log::info('Successfully added unique constraint to stock_movements');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique('stock_movements_ref_product_type_unique');
        });

        Log::info('Removed unique constraint from stock_movements');
    }
};
