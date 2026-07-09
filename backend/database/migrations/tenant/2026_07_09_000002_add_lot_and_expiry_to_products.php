<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add product-level lot_number + expiry_date so batch-tracked products can
 * record a default lot/expiry directly on the product (and via CSV import).
 * Multi-batch scenarios still use the product_batches table at stock-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('products', 'lot_number')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('lot_number')->nullable()->after('shelf_life_days');
            });
        }

        if (!Schema::hasColumn('products', 'expiry_date')) {
            Schema::table('products', function (Blueprint $table) {
                $table->date('expiry_date')->nullable()->after('lot_number');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'expiry_date')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('expiry_date');
            });
        }

        if (Schema::hasColumn('products', 'lot_number')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('lot_number');
            });
        }
    }
};
