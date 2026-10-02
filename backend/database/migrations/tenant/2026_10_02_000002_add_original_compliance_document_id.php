<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #663: a replacement invoice is submitted as a new compliance document
 * that references the original, instead of reusing the validated document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('acct_compliance_documents')) {
            return;
        }

        if (! Schema::hasColumn('acct_compliance_documents', 'original_compliance_document_id')) {
            Schema::table('acct_compliance_documents', function (Blueprint $table) {
                $table->unsignedBigInteger('original_compliance_document_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('acct_compliance_documents')) {
            return;
        }

        if (Schema::hasColumn('acct_compliance_documents', 'original_compliance_document_id')) {
            Schema::table('acct_compliance_documents', function (Blueprint $table) {
                $table->dropColumn('original_compliance_document_id');
            });
        }
    }
};
