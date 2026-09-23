<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence confirmation: the vendor (invoice creator) verifies a customer's
 * uploaded evidence (acceptance signature, payment proof) before the invoice
 * is treated as settled. Mirrors the "Evidence confirmed" step on the customer
 * acceptance status trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoice_evidence')) {
            return;
        }

        Schema::table('invoice_evidence', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_evidence', 'confirmed_at')) {
                $table->timestamp('confirmed_at')->nullable()->index();
            }
            if (! Schema::hasColumn('invoice_evidence', 'confirmed_by')) {
                $table->unsignedBigInteger('confirmed_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoice_evidence')) {
            return;
        }

        Schema::table('invoice_evidence', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_evidence', 'confirmed_at')) {
                $table->dropColumn('confirmed_at');
            }
            if (Schema::hasColumn('invoice_evidence', 'confirmed_by')) {
                $table->dropColumn('confirmed_by');
            }
        });
    }
};
