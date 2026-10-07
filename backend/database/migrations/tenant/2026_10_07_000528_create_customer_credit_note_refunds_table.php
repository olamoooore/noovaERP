<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #528: a posted credit note's remaining credit can be refunded to the
 * customer. Each refund is an outbound payment (a payments row with a
 * negative amount, like the existing refund corrections) posted through the
 * PostingEngine; this table links it to the credit note so the note's
 * remaining credit is derived from it (total − applied − refunded).
 *
 * Voided, never deleted. New table only — no existing data is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_credit_note_refunds')) {
            return;
        }

        Schema::create('customer_credit_note_refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('credit_note_id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency_code', 3)->nullable();
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->unsignedBigInteger('reversal_journal_entry_id')->nullable();
            $table->unsignedBigInteger('reversal_payment_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('credit_note_id')->references('id')->on('customer_credit_notes')->cascadeOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
            $table->index(['credit_note_id', 'voided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_note_refunds');
    }
};
