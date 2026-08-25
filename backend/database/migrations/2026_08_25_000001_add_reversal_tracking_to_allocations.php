<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds reversal-tracking columns to the allocations table
 * for compensating-record settlement reversals (P0-5, ACC-005B).
 *
 * Idempotent: each column is added only if missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Use raw SQL with IF NOT EXISTS for idempotency on PostgreSQL.
        // Laravel's Schema::hasColumn() + table() pattern swallows DB errors
        // across separate ALTERs, which masks partial-failure states.
        $columns = [
            'reversed_at' => 'timestamp(0) without time zone',
            'reversal_of_allocation_id' => 'bigint',
            'reversal_reason' => 'varchar(500)',
        ];

        foreach ($columns as $name => $type) {
            $exists = DB::scalar(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_name = 'allocations' AND column_name = ? LIMIT 1",
                [$name]
            );
            if (! $exists) {
                DB::statement("ALTER TABLE allocations ADD COLUMN {$name} {$type} NULL");
            }
        }

        // Index for compensation lookups by reversal_of_allocation_id
        $idxExists = DB::scalar(
            "SELECT 1 FROM pg_indexes
             WHERE tablename = 'allocations' AND indexname = 'allocations_reversal_of_allocation_id_idx' LIMIT 1"
        );
        if (! $idxExists) {
            DB::statement('CREATE INDEX allocations_reversal_of_allocation_id_idx ON allocations (reversal_of_allocation_id)');
        }

        // Self-referencing FK only if not already present
        $fkExists = DB::scalar(
            "SELECT 1 FROM information_schema.table_constraints
             WHERE table_name = 'allocations'
               AND constraint_type = 'FOREIGN KEY'
               AND constraint_name = 'allocations_reversal_of_allocation_id_foreign' LIMIT 1"
        );
        if (! $fkExists) {
            DB::statement(
                'ALTER TABLE allocations
                 ADD CONSTRAINT allocations_reversal_of_allocation_id_foreign
                 FOREIGN KEY (reversal_of_allocation_id) REFERENCES allocations(id) ON DELETE SET NULL'
            );
        }
    }

    public function down(): void
    {
        // Drop FK and columns if they exist
        DB::statement(
            'ALTER TABLE allocations
             DROP CONSTRAINT IF EXISTS allocations_reversal_of_allocation_id_foreign'
        );
        DB::statement('DROP INDEX IF EXISTS allocations_reversal_of_allocation_id_idx');
        DB::statement('ALTER TABLE allocations DROP COLUMN IF EXISTS reversal_reason');
        DB::statement('ALTER TABLE allocations DROP COLUMN IF EXISTS reversal_of_allocation_id');
        DB::statement('ALTER TABLE allocations DROP COLUMN IF EXISTS reversed_at');
    }
};
