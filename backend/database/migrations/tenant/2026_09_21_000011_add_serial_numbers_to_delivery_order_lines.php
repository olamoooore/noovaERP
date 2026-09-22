<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #131: delivery lines carry the serial numbers consumed by the sale so
 * ProductSerial::markSold() can be wired at fulfilment time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('delivery_order_lines') && ! Schema::hasColumn('delivery_order_lines', 'serial_numbers')) {
            Schema::table('delivery_order_lines', function (Blueprint $table) {
                $table->json('serial_numbers')->nullable()->after('batch_number');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('delivery_order_lines', 'serial_numbers')) {
            Schema::table('delivery_order_lines', function (Blueprint $table) {
                $table->dropColumn('serial_numbers');
            });
        }
    }
};
