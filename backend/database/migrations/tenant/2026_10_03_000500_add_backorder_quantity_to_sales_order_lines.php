<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #379 (O10): a confirmed order line whose stock could not be reserved was a
 * silent log line — the user and the list saw nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->decimal('backorder_quantity', 15, 4)->default(0)->after('quantity_cancelled');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropColumn('backorder_quantity');
        });
    }
};
