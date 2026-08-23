<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_payments', function (Blueprint $table) {
            // Nullable FK to the original payment this entry refunds or voids.
            // Used by RecoverStuckPosOrders to exclude refund/void payments
            // from the "stuck draft order" recovery scan.
            if (! Schema::hasColumn('pos_payments', 'refund_for_payment_id')) {
                $table->unsignedBigInteger('refund_for_payment_id')->nullable()->after('payment_method_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_payments', function (Blueprint $table) {
            if (Schema::hasColumn('pos_payments', 'refund_for_payment_id')) {
                $table->dropColumn('refund_for_payment_id');
            }
        });
    }
};
