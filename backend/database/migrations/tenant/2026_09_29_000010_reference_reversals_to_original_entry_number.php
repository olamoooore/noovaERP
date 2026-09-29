<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existing reversal entries referenced only the original's business
     * reference (e.g. an invoice number) — they never said WHICH journal entry
     * they reverse. Mirror the customer-payment convention: reference the
     * entry number ("Reversal: JE-2026-0031") on every reversal row.
     *
     * Descriptions are left untouched (they are already generated text); the
     * engine writes the "… of <entry number>" description for new reversals.
     */
    public function up(): void
    {
        if (! Schema::hasTable('journal_entries')) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE journal_entries AS r
            SET reference = 'Reversal: ' || o.entry_number,
                updated_at = NOW()
            FROM journal_entries AS o
            WHERE o.id = r.reversed_entry_id
              AND r.is_reversal = TRUE
              AND r.reference IS DISTINCT FROM 'Reversal: ' || o.entry_number
        SQL);
    }

    public function down(): void
    {
        // Keep the references: they are derived lineage, not user data.
    }
};