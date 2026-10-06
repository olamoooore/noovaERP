<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #607 (RPT-F1) — one row per statement send attempt (mirrors the
 * invoice_deliveries pattern): channel, recipient, outcome and the batch it
 * belongs to. The statement history row itself stays in customer_statements;
 * this is the per-channel send log the batch progress and audit read.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_statement_deliveries')) {
            return;
        }

        Schema::create('customer_statement_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_statement_id')->constrained('customer_statements')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('customer_statement_batch_id')->nullable()
                ->constrained('customer_statement_batches')->nullOnDelete();
            $table->string('channel', 20); // email | whatsapp
            $table->string('recipient')->nullable();
            $table->string('status', 20)->default('sent'); // sent | failed
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['customer_statement_id', 'created_at']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_statement_deliveries');
    }
};
