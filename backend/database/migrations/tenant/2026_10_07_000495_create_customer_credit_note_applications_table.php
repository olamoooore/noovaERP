<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #495: a posted credit note's remaining credit (what its own invoice
 * could not absorb, #510) can be applied to another open invoice of the same
 * customer and legal entity — "Outstanding credits" on the invoice.
 *
 * Mirrors payment_applications (#155): one explicit row per application,
 * reversed (never deleted) when the credit note is voided, so the remaining
 * credit and the audit trail are derived from it. New table only — no
 * existing data is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_credit_note_applications')) {
            return;
        }

        Schema::create('customer_credit_note_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('credit_note_id');
            $table->unsignedBigInteger('invoice_id');
            $table->decimal('applied_amount', 15, 2);
            $table->string('currency_code', 3)->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('credit_note_id')->references('id')->on('customer_credit_notes')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            $table->index(['credit_note_id', 'reversed_at']);
            $table->index(['invoice_id', 'credit_note_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_note_applications');
    }
};
