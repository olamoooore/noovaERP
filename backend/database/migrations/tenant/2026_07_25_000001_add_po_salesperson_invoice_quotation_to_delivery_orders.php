<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->string('po_number', 100)->nullable()->after('external_reference');
            $table->string('salesperson', 255)->nullable()->after('po_number');
            $table->unsignedBigInteger('invoice_id')->nullable()->after('salesperson');
            $table->unsignedBigInteger('quotation_id')->nullable()->after('invoice_id');
            $table->decimal('total', 15, 2)->nullable()->after('notes');
        });

        Schema::table('delivery_order_lines', function (Blueprint $table) {
            $table->decimal('unit_price', 15, 2)->nullable()->after('quantity_delivered');
            $table->decimal('line_total', 15, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropColumn(['po_number', 'salesperson', 'invoice_id', 'quotation_id', 'total']);
        });

        Schema::table('delivery_order_lines', function (Blueprint $table) {
            $table->dropColumn(['unit_price', 'line_total']);
        });
    }
};
