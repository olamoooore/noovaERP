<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ocr_documents')) {
            return;
        }

        DB::statement('
            ALTER TABLE ocr_documents
            DROP CONSTRAINT IF EXISTS ocr_documents_status_check
        ');

        DB::statement("
            ALTER TABLE ocr_documents
            ADD CONSTRAINT ocr_documents_status_check
            CHECK (status IN (
                'PENDING',
                'PROCESSING',
                'NEEDS_REVIEW',
                'POSTED',
                'PROMOTED',
                'EXTRACTION_FAILED'
            ))
        ");
    }

    public function down(): void
    {
        if (! Schema::hasTable('ocr_documents')) {
            return;
        }

        DB::statement('
            ALTER TABLE ocr_documents
            DROP CONSTRAINT IF EXISTS ocr_documents_status_check
        ');

        DB::statement("
            ALTER TABLE ocr_documents
            ADD CONSTRAINT ocr_documents_status_check
            CHECK (status IN (
                'PENDING',
                'PROCESSING',
                'NEEDS_REVIEW',
                'POSTED',
                'EXTRACTION_FAILED'
            ))
        ");
    }
};
