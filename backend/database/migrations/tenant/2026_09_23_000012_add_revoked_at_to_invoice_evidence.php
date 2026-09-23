<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revoked acceptance links: when an invoice is withdrawn (reset to draft) or
 * cancelled, its outstanding customer acceptance links must stop working so a
 * customer cannot accept an invoice that is being reissued.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoice_evidence')) {
            return;
        }

        Schema::table('invoice_evidence', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_evidence', 'revoked_at')) {
                $table->timestamp('revoked_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoice_evidence')) {
            return;
        }

        Schema::table('invoice_evidence', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_evidence', 'revoked_at')) {
                $table->dropColumn('revoked_at');
            }
        });
    }
};
