<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('web_orders') || !Schema::hasTable('warehouses') || Schema::hasColumn('web_orders', 'fulfillment_warehouse_id')) {
            return;
        }

        Schema::table('web_orders', function (Blueprint $table) {
            $table->foreignId('fulfillment_warehouse_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('warehouses')
                ->nullOnDelete();

            $table->index('fulfillment_warehouse_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('web_orders') || !Schema::hasColumn('web_orders', 'fulfillment_warehouse_id')) {
            return;
        }

        Schema::table('web_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fulfillment_warehouse_id');
        });
    }
};