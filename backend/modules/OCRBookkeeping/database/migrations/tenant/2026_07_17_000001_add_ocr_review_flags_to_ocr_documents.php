<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ocr_documents')) {
            return;
        }

        Schema::table('ocr_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('ocr_documents', 'ocr_review_flags')) {
                $table->json('ocr_review_flags')->nullable()->after('ocr_confidence_scores');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ocr_documents')) {
            return;
        }

        Schema::table('ocr_documents', function (Blueprint $table) {
            if (Schema::hasColumn('ocr_documents', 'ocr_review_flags')) {
                $table->dropColumn('ocr_review_flags');
            }
        });
    }
};