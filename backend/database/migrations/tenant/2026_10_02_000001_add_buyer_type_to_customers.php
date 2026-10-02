<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #664: customers need a buyer type the Malaysia e-invoice pack can
 * trust. The legacy `type` column is an enum('individual','company') and
 * cannot express the government / foreign buyer categories, so add a nullable
 * `buyer_type` string. No backfill: the pack derives company/individual from
 * the legacy enum until a customer explicitly sets a buyer type.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers') || Schema::hasColumn('customers', 'buyer_type')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->string('buyer_type', 20)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'buyer_type')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('buyer_type');
        });
    }
};
