<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #252: web orders could not capture serials either (see the POS
 * companion migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('web_order_items') && ! Schema::hasColumn('web_order_items', 'serial_numbers')) {
            Schema::table('web_order_items', function (Blueprint $table) {
                $table->json('serial_numbers')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('web_order_items') && Schema::hasColumn('web_order_items', 'serial_numbers')) {
            Schema::table('web_order_items', function (Blueprint $table) {
                $table->dropColumn('serial_numbers');
            });
        }
    }
};
