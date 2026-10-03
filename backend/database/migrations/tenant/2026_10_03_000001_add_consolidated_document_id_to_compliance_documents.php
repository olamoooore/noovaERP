<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #815 (EP4) AC2 — a receipt held for B2C monthly consolidation links to the
 * consolidated document that carries it (whose submission holds the LHDN UUID).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('acct_compliance_documents')
            && ! Schema::hasColumn('acct_compliance_documents', 'consolidated_document_id')) {
            Schema::table('acct_compliance_documents', function (Blueprint $table) {
                $table->unsignedBigInteger('consolidated_document_id')->nullable()->after('document_id');
                $table->index(['tenant_id', 'consolidated_document_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('acct_compliance_documents')
            && Schema::hasColumn('acct_compliance_documents', 'consolidated_document_id')) {
            Schema::table('acct_compliance_documents', function (Blueprint $table) {
                $table->dropIndex(['tenant_id', 'consolidated_document_id']);
                $table->dropColumn('consolidated_document_id');
            });
        }
    }
};
