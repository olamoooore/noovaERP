<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_lines', function (Blueprint $table) {
            $table->foreignId('purchase_order_item_id')
                ->nullable()
                ->after('account_id')
                ->constrained('purchase_order_items')
                ->nullOnDelete();

            $table->foreignId('goods_receipt_line_id')
                ->nullable()
                ->after('purchase_order_item_id')
                ->constrained('goods_receipt_lines')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bill_lines', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_item_id']);
            $table->dropForeign(['goods_receipt_line_id']);
            $table->dropColumn(['purchase_order_item_id', 'goods_receipt_line_id']);
        });
    }
};
