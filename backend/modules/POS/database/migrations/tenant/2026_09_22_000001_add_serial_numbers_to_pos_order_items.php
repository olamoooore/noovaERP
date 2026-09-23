<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #252: serialized products sold through POS never captured their serials,
 * so markSerialsSold() (only wired into the Sales-Order→Delivery-Order channel)
 * could not run — serials stayed 'available' after a sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_order_items') && ! Schema::hasColumn('pos_order_items', 'serial_numbers')) {
            Schema::table('pos_order_items', function (Blueprint $table) {
                $table->json('serial_numbers')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_order_items') && Schema::hasColumn('pos_order_items', 'serial_numbers')) {
            Schema::table('pos_order_items', function (Blueprint $table) {
                $table->dropColumn('serial_numbers');
            });
        }
    }
};
