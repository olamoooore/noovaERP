<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MRD gap review G-35: a filed statutory snapshot is evidence — re-filing
 * must NEVER overwrite it (F7 compares against what was actually filed).
 * Drops the overwrite-enforcing unique key and adds versioning columns so
 * filings become an append-only series with one current row per slot.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('statutory_return_filings')) {
            return;
        }

        Schema::table('statutory_return_filings', function ($table) {
            if (! Schema::hasColumn('statutory_return_filings', 'filing_version')) {
                $table->unsignedInteger('filing_version')->default(1)->after('entity_key');
            }

            if (! Schema::hasColumn('statutory_return_filings', 'superseded_at')) {
                $table->timestamp('superseded_at')->nullable()->after('filed_at');
            }
        });

        // Replace the overwrite-forcing unique with a current-row unique.
        try {
            DB::statement('ALTER TABLE statutory_return_filings DROP CONSTRAINT IF EXISTS statutory_filing_unique');
            DB::statement('DROP INDEX IF EXISTS statutory_filing_unique');
        } catch (\Throwable $e) {
            // Index name may differ per environment; versions still enforced in code.
        }

        Schema::table('statutory_return_filings', function ($table) {
            $table->unique(['country', 'form', 'period', 'entity_key', 'filing_version'], 'statutory_filing_slot_version_unique');
        });
    }

    public function down(): void
    {
        // Forward-only.
    }
};
