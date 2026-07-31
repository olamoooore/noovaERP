<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->string('model_number', 100)->nullable()->after('product_id');
        });

        Schema::table('quotation_lines', function (Blueprint $table) {
            $table->string('model_number', 100)->nullable()->after('product_id');
        });

        Schema::table('delivery_order_lines', function (Blueprint $table) {
            $table->string('model_number', 100)->nullable()->after('product_id');
        });

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->string('model_number', 100)->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn('model_number');
        });

        Schema::table('quotation_lines', function (Blueprint $table) {
            $table->dropColumn('model_number');
        });

        Schema::table('delivery_order_lines', function (Blueprint $table) {
            $table->dropColumn('model_number');
        });

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropColumn('model_number');
        });
    }
};
