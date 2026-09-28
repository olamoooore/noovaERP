<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * storefront_order_payment_attempts — every intent/attempt recorded
 * (PAY-R15): gateway, platform transaction ref, status, error. Never the
 * source of truth for completion (that stays PaymentTransactionCompletion-
 * Service), always the audit of what was tried.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_order_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('storefront_orders')->cascadeOnDelete();
            $table->unsignedInteger('attempt_no');
            $table->string('gateway', 40);
            $table->string('transaction_ref', 191)->nullable();
            $table->string('status', 40);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('transaction_ref');
            $table->unique(['order_id', 'attempt_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_order_payment_attempts');
    }
};
