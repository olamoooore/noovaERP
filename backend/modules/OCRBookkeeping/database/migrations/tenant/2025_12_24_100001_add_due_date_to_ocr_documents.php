<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ocr_documents', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('document_date');
            $table->index('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ocr_documents', function (Blueprint $table) {
            $table->dropIndex(['due_date']);
            $table->dropColumn('due_date');
        });
    }
};
