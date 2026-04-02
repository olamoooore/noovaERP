<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the restrictive CHECK constraint that only allows
        // 'manual','invoice','payment','adjustment','opening'
        // but the PostingEngine needs: pos_order, credit_note, bill,
        // vendor_credit_note, ocr_document, web_order, fx_revaluation, etc.
        DB::statement("ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_type_check");

        // Ensure column is VARCHAR(50) to accept any document type string
        DB::statement("ALTER TABLE journal_entries ALTER COLUMN type TYPE VARCHAR(50)");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_type_check CHECK (type::text = ANY (ARRAY['manual','invoice','payment','adjustment','opening','pos_order','credit_note','bill','vendor_credit_note','ocr_document','web_order','fx_revaluation','depreciation','asset_disposal','bank_adjustment']::text[]))");
    }
};
