<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_orders', 'warehouse_id')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->foreignId('warehouse_id')
                    ->nullable()
                    ->after('required_date')
                    ->constrained('warehouses')
                    ->nullOnDelete();

                $table->index('warehouse_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_orders', 'warehouse_id')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->dropForeign(['warehouse_id']);
                $table->dropIndex(['warehouse_id']);
                $table->dropColumn('warehouse_id');
            });
        }
    }
};
