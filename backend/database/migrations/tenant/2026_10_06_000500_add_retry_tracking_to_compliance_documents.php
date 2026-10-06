<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #673 (EINV-UX4) — a failed / invalid e-invoice can be retried from the
 * queue; the document keeps how many times it was retried and when.
 * Additive only: existing rows start at zero retries.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('acct_compliance_documents')) {
            return;
        }

        Schema::table('acct_compliance_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('acct_compliance_documents', 'retry_count')) {
                $table->unsignedInteger('retry_count')->default(0);
            }
            if (! Schema::hasColumn('acct_compliance_documents', 'last_retried_at')) {
                $table->timestamp('last_retried_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('acct_compliance_documents')) {
            return;
        }

        Schema::table('acct_compliance_documents', function (Blueprint $table) {
            foreach (['retry_count', 'last_retried_at'] as $column) {
                if (Schema::hasColumn('acct_compliance_documents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
