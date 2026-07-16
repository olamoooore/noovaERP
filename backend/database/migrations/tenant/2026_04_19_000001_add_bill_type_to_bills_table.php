<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add bill_type to tenant bills so AP can distinguish expense bills from
     * procurement bills that require PO/GRN three-way matching.
     */
    public function up(): void
    {
        if (! Schema::hasTable('bills')) {
            return;
        }

        if (! Schema::hasColumn('bills', 'bill_type')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->string('bill_type')->default('expense')->after('status');
            });
        }

        if (Schema::hasColumn('bills', 'purchase_order_id')) {
            DB::table('bills')
                ->whereNotNull('purchase_order_id')
                ->update(['bill_type' => 'procurement']);
        }

        if (Schema::hasColumn('bills', 'goods_receipt_id')) {
            DB::table('bills')
                ->whereNotNull('goods_receipt_id')
                ->update(['bill_type' => 'procurement']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('bills') || ! Schema::hasColumn('bills', 'bill_type')) {
            return;
        }

        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn('bill_type');
        });
    }
};
