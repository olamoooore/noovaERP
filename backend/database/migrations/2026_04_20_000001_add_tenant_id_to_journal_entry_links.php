<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entry_links', function (Blueprint $table) {
            $table->foreignId('tenant_id')
                ->after('id')
                ->constrained('tenants')
                ->onDelete('restrict');

            $table->string('request_id', 100)->nullable()->after('source_document_id');
            $table->string('correlation_id', 100)->nullable()->after('request_id');

            $table->unique(
                ['tenant_id', 'source_document_type', 'source_document_id', 'relation_type'],
                'journal_entry_links_post_unique'
            );

            $table->unique(
                ['tenant_id', 'original_journal_entry_id', 'relation_type'],
                'journal_entry_links_reversal_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_links', function (Blueprint $table) {
            $table->dropUnique('journal_entry_links_post_unique');
            $table->dropUnique('journal_entry_links_reversal_unique');
            $table->dropColumn(['correlation_id', 'request_id', 'tenant_id']);
        });
    }
};