<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop the restrictive journal_entries.type CHECK constraint.
 *
 * 2026_04_02_190000 deliberately dropped it (the PostingEngine legitimately
 * posts many document types). 2026_08_28_000003 re-added a whitelist to make
 * room for 'period_close' — but omitted 'stock_adjustment', so every
 * stock-adjustment / batch-serial import posting failed with:
 *   "new row for relation journal_entries violates check constraint
 *    journal_entries_type_check".
 *
 * No whitelist: the document type is application-owned, and a DB enum domain
 * here only creates drift when a new posting type is introduced.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_type_check');
    }

    public function down(): void
    {
        // Intentionally not re-added — see class docblock.
    }
};
