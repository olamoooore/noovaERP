<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['quotation_lines', 'invoice_lines', 'sales_order_lines', 'delivery_order_lines', 'purchase_order_items'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            if (! Schema::hasColumn($tableName, 'line_type')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->string('line_type', 20)->default('product')->after('id');
                });
            }

            if (! Schema::hasColumn($tableName, 'sequence')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->unsignedInteger('sequence')->default(0)->after('line_type');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['quotation_lines', 'invoice_lines', 'sales_order_lines', 'delivery_order_lines', 'purchase_order_items'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            if (Schema::hasColumn($tableName, 'sequence')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('sequence'));
            }

            // invoice_lines.line_type predates this migration (2026_04_20_000002).
            if ($tableName !== 'invoice_lines' && Schema::hasColumn($tableName, 'line_type')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('line_type'));
            }
        }
    }
};
