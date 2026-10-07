<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #500 — one row per payment-receipt send attempt (mirrors the
 * invoice_deliveries / customer_statement_deliveries pattern): channel,
 * recipient, outcome. It is the receipt's delivery history on the payment
 * detail. Additive only; no existing data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_receipt_deliveries')) {
            return;
        }

        Schema::create('payment_receipt_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('channel', 20); // email | whatsapp
            $table->string('recipient')->nullable();
            $table->string('status', 20)->default('sent'); // sent | failed
            $table->text('error')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipt_deliveries');
    }
};
