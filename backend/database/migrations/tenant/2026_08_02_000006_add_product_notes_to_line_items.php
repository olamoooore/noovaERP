<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['invoice_lines', 'quotation_lines', 'delivery_order_lines', 'sales_order_lines'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'product_notes')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->text('product_notes')->nullable()->after('description');
                });
            }
        }
    }

    public function down(): void
    {
        $tables = ['invoice_lines', 'quotation_lines', 'delivery_order_lines', 'sales_order_lines'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'product_notes')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn('product_notes');
                });
            }
        }
    }
};
