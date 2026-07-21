<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ocr_documents')) {
            return;
        }

        Schema::table('ocr_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('ocr_documents', 'customer_id')) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('vendor_name');
            }

            if (!Schema::hasColumn('ocr_documents', 'customer_name')) {
                $table->string('customer_name')->nullable()->after('customer_id');
            }
        });

        DB::statement('ALTER TABLE ocr_documents DROP CONSTRAINT IF EXISTS ocr_documents_customer_id_foreign');

        DB::statement('
            ALTER TABLE ocr_documents
            ADD CONSTRAINT ocr_documents_customer_id_foreign
            FOREIGN KEY (customer_id)
            REFERENCES customers(id)
            ON DELETE RESTRICT
        ');
    }

    public function down(): void
    {
        if (!Schema::hasTable('ocr_documents')) {
            return;
        }

        DB::statement('ALTER TABLE ocr_documents DROP CONSTRAINT IF EXISTS ocr_documents_customer_id_foreign');

        Schema::table('ocr_documents', function (Blueprint $table) {
            if (Schema::hasColumn('ocr_documents', 'customer_name')) {
                $table->dropColumn('customer_name');
            }

            if (Schema::hasColumn('ocr_documents', 'customer_id')) {
                $table->dropColumn('customer_id');
            }
        });
    }
};