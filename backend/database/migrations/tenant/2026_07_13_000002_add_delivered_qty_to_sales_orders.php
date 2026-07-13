<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->decimal('quantity_delivered', 15, 4)->default(0)->after('quantity_fulfilled');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->boolean('bill_by_delivered_qty')->default(false)->after('accounting_status');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('bill_by_delivered_qty');
        });

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropColumn('quantity_delivered');
        });
    }
};
