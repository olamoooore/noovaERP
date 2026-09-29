<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Order-level fulfillment warehouse chosen at placement (mirrors the
     * legacy web_orders.fulfillment_warehouse_id precedent): reservations are
     * made against this single warehouse so fulfilment and release jobs can
     * find them without re-solving availability.
     */
    public function up(): void
    {
        Schema::table('storefront_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('fulfillment_warehouse_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('storefront_orders', function (Blueprint $table) {
            $table->dropColumn('fulfillment_warehouse_id');
        });
    }
};
