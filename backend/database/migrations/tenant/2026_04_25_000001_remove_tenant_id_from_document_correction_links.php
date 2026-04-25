<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the redundant tenant_id column and its cross-schema FK from
 * document_correction_links. The table lives in the tenant schema and is
 * already scoped by PostgreSQL search_path — a tenant_id column is both
 * unnecessary and architecturally incorrect (tenant schema → central schema FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_correction_links', function (Blueprint $table) {
            // Drop indexes that include tenant_id first
            $table->dropIndex(['tenant_id', 'document_type', 'document_id']);
            $table->dropIndex(['tenant_id', 'related_document_type', 'related_document_id']);
            $table->dropIndex(['tenant_id', 'relation_type']);

            // Drop the cross-schema FK then the column
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });

        // Re-add the indexes without tenant_id
        Schema::table('document_correction_links', function (Blueprint $table) {
            $table->index(['document_type', 'document_id']);
            $table->index(['related_document_type', 'related_document_id']);
            $table->index(['relation_type']);
        });
    }

    public function down(): void
    {
        Schema::table('document_correction_links', function (Blueprint $table) {
            $table->dropIndex(['document_type', 'document_id']);
            $table->dropIndex(['related_document_type', 'related_document_id']);
            $table->dropIndex(['relation_type']);

            $table->uuid('tenant_id')->nullable()->after('id');
            $table->index(['tenant_id', 'document_type', 'document_id']);
            $table->index(['tenant_id', 'related_document_type', 'related_document_id']);
            $table->index(['tenant_id', 'relation_type']);
        });
    }
};
