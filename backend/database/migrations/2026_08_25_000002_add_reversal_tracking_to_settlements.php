<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds reversal-tracking columns to the settlements table
 * for compensating-record settlement reversals (P0-5, ACC-005B).
 *
 * Idempotent: each column is added only if missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'reversed_at' => 'timestamp(0) without time zone',
            'reversal_reason' => 'varchar(500)',
        ] as $name => $type) {
            $exists = DB::scalar(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_name = 'settlements' AND column_name = ? LIMIT 1",
                [$name]
            );
            if (! $exists) {
                DB::statement("ALTER TABLE settlements ADD COLUMN {$name} {$type} NULL");
            }
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE settlements DROP COLUMN IF EXISTS reversal_reason');
        DB::statement('ALTER TABLE settlements DROP COLUMN IF EXISTS reversed_at');
    }
};
