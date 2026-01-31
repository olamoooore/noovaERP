<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add OCR Document Reference to Journal Entries
     * Creates audit trail from journal entries back to source OCR documents
     * Enables complete traceability for compliance and auditing
     */
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            // Add nullable foreign key to ocr_documents
            $table->foreignId('ocr_document_id')
                ->nullable()
                ->after('created_by')
                ->constrained('ocr_documents')
                ->onDelete('restrict');
            
            // Add index for performance
            $table->index('ocr_document_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['ocr_document_id']);
            $table->dropIndex(['ocr_document_id']);
            $table->dropColumn('ocr_document_id');
        });
    }
};
