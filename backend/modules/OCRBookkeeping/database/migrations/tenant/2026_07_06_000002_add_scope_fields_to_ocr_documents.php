<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ocr_documents')) {
            return;
        }

        Schema::table('ocr_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('ocr_documents', 'legal_entity_id')) {
                $table->unsignedBigInteger('legal_entity_id')->nullable()->after('vendor_id')->index();
            }

            if (! Schema::hasColumn('ocr_documents', 'entity_book_id')) {
                $table->unsignedBigInteger('entity_book_id')->nullable()->after('legal_entity_id')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ocr_documents')) {
            return;
        }

        Schema::table('ocr_documents', function (Blueprint $table) {
            if (Schema::hasColumn('ocr_documents', 'entity_book_id')) {
                $table->dropColumn('entity_book_id');
            }

            if (Schema::hasColumn('ocr_documents', 'legal_entity_id')) {
                $table->dropColumn('legal_entity_id');
            }
        });
    }
};
