<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends tax_transaction_details with the persisted fields required by
 * the canonical TaxDeterminationResult (#42): rate, treatment, regime,
 * result_version, determination_id. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'tax_determination_id' => 'uuid',
            'tax_rate' => 'decimal(8,6)',
            'tax_treatment' => 'varchar(40)',
            'tax_regime' => 'varchar(40)',
            'result_version' => 'integer',
        ];

        foreach ($columns as $name => $type) {
            $exists = DB::scalar(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_name = 'tax_transaction_details' AND column_name = ? LIMIT 1",
                [$name]
            );
            if (! $exists) {
                DB::statement("ALTER TABLE tax_transaction_details ADD COLUMN {$name} {$type} NULL");
            }
        }
    }

    public function down(): void
    {
        foreach (['tax_determination_id', 'tax_rate', 'tax_treatment', 'tax_regime', 'result_version'] as $col) {
            DB::statement("ALTER TABLE tax_transaction_details DROP COLUMN IF EXISTS {$col}");
        }
    }
};
