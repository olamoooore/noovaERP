<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('ocr_documents', 'file_hash')) {
            Schema::table('ocr_documents', function (Blueprint $table) {
                $table->string('file_hash', 64)->nullable()->after('file_name');
                $table->index('file_hash');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ocr_documents', 'file_hash')) {
            Schema::table('ocr_documents', function (Blueprint $table) {
                $table->dropColumn('file_hash');
            });
        }
    }
};