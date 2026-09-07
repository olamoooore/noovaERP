<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACC-001 Opening Balance — line-level detail.
 *
 * Issue #70: each line represents one account's opening balance
 * within a batch. Lines are immutable once the batch leaves DRAFT —
 * corrections create a new superseding batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balance_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->constrained('opening_balance_batches')->cascadeOnDelete();

            // Account binding
            $table->unsignedBigInteger('account_id');
            $table->string('account_code', 50); // denormalized for idempotency

            // Amounts
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('currency_code', 3)->default('USD');
            $table->decimal('exchange_rate', 18, 8)->nullable();
            $table->decimal('original_amount', 18, 2)->nullable();

            // Source lineage
            $table->string('source_line_ref')->nullable();
            $table->string('source_hash', 64)->nullable();

            // Per-line status
            $table->enum('status', ['active', 'superseded', 'rejected'])->default('active');

            // Audit
            $table->timestamps();

            // Indexes
            $table->index(['batch_id', 'status']);
            $table->index('account_id');
            $table->index('source_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_lines');
    }
};
