<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #106 (BLOCKER): stock adjustments never posted to the GL.
 *
 * StockAdjustmentReason had no way to route different adjustment types
 * (shrinkage, damage, expiry, count correction, found stock) to different GL
 * accounts. This adds the mapping key consumed by AccountMappingService when
 * StockAdjustmentService posts the valuation change.
 *
 * Nullable: when unset, decreases default to the 'inventory_shrinkage' mapping
 * and increases to 'inventory_adjustment_gain'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_adjustment_reasons')
            && ! Schema::hasColumn('stock_adjustment_reasons', 'expense_account_mapping_key')) {
            Schema::table('stock_adjustment_reasons', function (Blueprint $table) {
                $table->string('expense_account_mapping_key', 80)->nullable()->after('type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stock_adjustment_reasons', 'expense_account_mapping_key')) {
            Schema::table('stock_adjustment_reasons', function (Blueprint $table) {
                $table->dropColumn('expense_account_mapping_key');
            });
        }
    }
};
