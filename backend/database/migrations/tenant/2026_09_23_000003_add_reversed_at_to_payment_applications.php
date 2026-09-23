<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hostile re-review C-3 (#155): reversing/refunding a split payment must undo
 * its allocations. Applications gain a reversed_at marker so the audit trail
 * is preserved while reversed rows stop counting as applied cash.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_applications') || Schema::hasColumn('payment_applications', 'reversed_at')) {
            return;
        }

        Schema::table('payment_applications', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('applied_amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_applications') || ! Schema::hasColumn('payment_applications', 'reversed_at')) {
            return;
        }

        Schema::table('payment_applications', function (Blueprint $table) {
            $table->dropColumn('reversed_at');
        });
    }
};
