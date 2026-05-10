<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE document_correction_links DROP CONSTRAINT IF EXISTS document_correction_links_relation_type_check');
        DB::statement("ALTER TABLE document_correction_links ADD CONSTRAINT document_correction_links_relation_type_check CHECK (relation_type IN ('reversal_of', 'replaced_by', 'replacement_for', 'credited_by', 'credit_note_for', 'refund_for', 'payment_unapplied_by', 'superseded_by', 'void'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE document_correction_links DROP CONSTRAINT IF EXISTS document_correction_links_relation_type_check');
        DB::statement("ALTER TABLE document_correction_links ADD CONSTRAINT document_correction_links_relation_type_check CHECK (relation_type IN ('reversal_of', 'replaced_by', 'replacement_for', 'credited_by', 'credit_note_for', 'refund_for', 'payment_unapplied_by', 'superseded_by'))");
    }
};
