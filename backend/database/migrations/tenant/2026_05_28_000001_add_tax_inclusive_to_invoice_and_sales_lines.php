<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->boolean('tax_inclusive')->default(false)->after('tax_rate');
        });

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->boolean('tax_inclusive')->default(false)->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn('tax_inclusive');
        });

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropColumn('tax_inclusive');
        });
    }
};
