<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bank_statement_transaction_id')->constrained('bank_statement_transactions')->restrictOnDelete();
            $table->foreignId('contra_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->decimal('amount', 24, 8);
            $table->string('description');
            $table->text('reason');
            $table->string('status', 16)->default('proposed');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('journal_entry_line_id')->nullable()->constrained('journal_entry_lines')->restrictOnDelete();
            $table->string('idempotency_key', 100)->unique();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['bank_statement_transaction_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_adjustments');
    }
};
