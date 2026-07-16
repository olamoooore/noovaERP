<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ocr_documents') || ! Schema::hasTable('vendors')) {
            return;
        }

        DB::statement('ALTER TABLE ocr_documents DROP CONSTRAINT IF EXISTS ocr_documents_vendor_id_foreign');

        DB::statement('
            ALTER TABLE ocr_documents
            ADD CONSTRAINT ocr_documents_vendor_id_foreign
            FOREIGN KEY (vendor_id)
            REFERENCES vendors(id)
            ON DELETE RESTRICT
        ');
    }

    public function down(): void
    {
        if (! Schema::hasTable('ocr_documents') || ! Schema::hasTable('customers')) {
            return;
        }

        DB::statement('ALTER TABLE ocr_documents DROP CONSTRAINT IF EXISTS ocr_documents_vendor_id_foreign');

        DB::statement('
            ALTER TABLE ocr_documents
            ADD CONSTRAINT ocr_documents_vendor_id_foreign
            FOREIGN KEY (vendor_id)
            REFERENCES customers(id)
            ON DELETE RESTRICT
        ');
    }
};
