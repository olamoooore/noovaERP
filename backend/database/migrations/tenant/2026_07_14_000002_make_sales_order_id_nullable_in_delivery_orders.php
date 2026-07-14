<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Make sales_order_id nullable to support standalone delivery orders
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->foreignId('sales_order_id')->nullable()->change();
        });

        // Make sales_order_line_id nullable to support standalone delivery order lines
        Schema::table('delivery_order_lines', function (Blueprint $table) {
            $table->foreignId('sales_order_line_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->foreignId('sales_order_id')->constrained('sales_orders')->onDelete('restrict')->change();
        });

        Schema::table('delivery_order_lines', function (Blueprint $table) {
            $table->foreignId('sales_order_line_id')->constrained('sales_order_lines')->onDelete('restrict')->change();
        });
    }
};
