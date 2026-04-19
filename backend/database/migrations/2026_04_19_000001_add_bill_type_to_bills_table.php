<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WS-07 FIX-02: Add bill_type to distinguish procurement bills from expense/service bills.
     *
     * 'procurement' — bill originated from a PO or GRN; three-way match enforced.
     * 'expense'     — bill for services, consulting, rent, utilities, etc.; no PO/GRN required.
     *
     * Existing bills are classified as 'expense' by default. Bills with a non-null
     * purchase_order_id or goods_receipt_id are back-filled to 'procurement'.
     */
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->string('bill_type')->default('expense')->after('status');
        });

        // Back-fill existing procurement-linked bills
        DB::statement("
            UPDATE bills
            SET bill_type = 'procurement'
            WHERE purchase_order_id IS NOT NULL
               OR goods_receipt_id  IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn('bill_type');
        });
    }
};
