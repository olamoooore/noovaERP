<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval flag on delivery orders: mirrors the invoice approval model so a
 * tenant can require approval before a delivery order is confirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_orders')) {
            return;
        }

        Schema::table('delivery_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('delivery_orders', 'requires_approval')) {
                $table->boolean('requires_approval')->default(false);
            }
            if (! Schema::hasColumn('delivery_orders', 'approval_status')) {
                $table->string('approval_status')->default('not_required');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('delivery_orders')) {
            return;
        }

        Schema::table('delivery_orders', function (Blueprint $table) {
            if (Schema::hasColumn('delivery_orders', 'requires_approval')) {
                $table->dropColumn('requires_approval');
            }
            if (Schema::hasColumn('delivery_orders', 'approval_status')) {
                $table->dropColumn('approval_status');
            }
        });
    }
};
