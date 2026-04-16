<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
        Schema::table('web_orders', function (Blueprint $table) {
            $table->dropForeign(['fulfillment_warehouse_id']);
            $table->dropIndex(['fulfillment_warehouse_id']);
            $table->dropColumn('fulfillment_warehouse_id');
        });
    }
};
