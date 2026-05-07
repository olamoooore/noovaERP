<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure one posting link per source document to enforce idempotency
        Schema::table('journal_entry_links', function (Blueprint $table) {
            // Composite unique to prevent multiple links for the same source document and type
            $table->unique(['source_document_type', 'source_document_id', 'relation_type'], 'journal_entry_links_source_post_unique');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_links', function (Blueprint $table) {
            $table->dropUnique('journal_entry_links_source_post_unique');
        });
    }
};
