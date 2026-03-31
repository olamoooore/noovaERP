<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->decimal('refunded_amount', 15, 2)->default(0)->after('amount');
            $table->text('refund_reason')->nullable()->after('refunded_amount');
            $table->timestamp('refunded_at')->nullable()->after('processed_at');
            $table->string('webhook_id')->nullable()->after('transaction_id');
            $table->timestamp('webhook_received_at')->nullable()->after('webhook_attempts');
            $table->integer('webhook_attempts')->default(0)->after('metadata');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'refunded_amount',
                'refund_reason',
                'refunded_at',
                'webhook_id',
                'webhook_received_at',
                'webhook_attempts',
            ]);
        });
    }
};
