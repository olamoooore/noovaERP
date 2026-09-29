<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payment_settlements — gateway settlement reconciliation records
 * (FR-PAY-06 / P-04a): one row per reconciled PaymentTransaction from a
 * settlement feed (gateway payout file), carrying gross/fee/net and the
 * clearing→bank GL entry that moved the money out of Gateway Receivable.
 * UNIQUE per transaction — the durable answer to "is this payment settled?"
 * and the idempotency key for re-ingesting the same file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_transaction_id')->unique()->constrained('payment_transactions')->cascadeOnDelete();
            $table->string('settlement_reference', 100)->nullable();
            $table->string('gateway_txn_id', 191);
            $table->decimal('gross_amount', 15, 2);
            $table->decimal('fee_amount', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2);
            $table->string('currency', 3);
            $table->date('settled_at')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable()->index();
            $table->timestamps();

            $table->index('gateway_txn_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settlements');
    }
};
