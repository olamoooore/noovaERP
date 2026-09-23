<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hostile re-review C-5 (#102): batch postings (fx_revaluation) legitimately
 * carry no single source document — PostingEngine skips the document link for
 * them but still writes the audit row, and the NOT NULL document_id made the
 * whole posting fail. The column is nullable now.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('posting_audit_logs') || ! Schema::hasColumn('posting_audit_logs', 'document_id')) {
            return;
        }

        Schema::table('posting_audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('document_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverting would fail for batch audit rows; intentionally irreversible.
    }
};
