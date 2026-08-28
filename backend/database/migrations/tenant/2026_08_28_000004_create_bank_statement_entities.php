<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_statements')) {
            Schema::create('bank_statements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bank_account_id')->constrained('bank_accounts')->onDelete('cascade');
                $table->date('statement_date');
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->decimal('opening_balance', 15, 2)->default(0);
                $table->decimal('closing_balance', 15, 2)->default(0);
                $table->string('currency_code', 3)->default('USD');
                $table->string('status', 20)->default('draft'); // draft, imported, reconciled, closed
                $table->string('source')->nullable(); // csv, api, manual
                $table->string('source_reference')->nullable();
                $table->timestamps();

                $table->index(['bank_account_id', 'statement_date']);
                $table->index('status');
            });
        }

        if (! Schema::hasTable('bank_statement_lines')) {
            Schema::create('bank_statement_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bank_statement_id')->constrained('bank_statements')->onDelete('cascade');
                $table->foreignId('bank_account_id')->constrained('bank_accounts')->onDelete('cascade');
                $table->date('transaction_date');
                $table->string('description')->nullable();
                $table->string('reference')->nullable();
                $table->decimal('debit', 15, 2)->default(0);
                $table->decimal('credit', 15, 2)->default(0);
                $table->decimal('amount', 15, 2)->default(0);
                $table->string('currency_code', 3)->default('USD');
                $table->string('status', 20)->default('unmatched'); // unmatched, matched, reconciled, excluded
                $table->foreignId('matched_journal_entry_line_id')->nullable()->constrained('journal_entry_lines');
                $table->timestamp('matched_at')->nullable();
                $table->timestamps();

                $table->index(['bank_statement_id', 'status']);
                $table->index(['bank_account_id', 'transaction_date']);
                $table->index(['status', 'transaction_date']);
            });
        }

        if (! Schema::hasTable('match_candidates')) {
            Schema::create('match_candidates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bank_statement_line_id')->constrained('bank_statement_lines')->onDelete('cascade');
                $table->foreignId('journal_entry_line_id')->constrained('journal_entry_lines')->onDelete('cascade');
                $table->string('match_type', 20); // exact, fuzzy, amount_only, manual
                $table->decimal('confidence', 5, 2)->default(0);
                $table->string('reason')->nullable();
                $table->boolean('accepted')->default(false);
                $table->timestamp('accepted_at')->nullable();
                $table->timestamps();

                $table->unique(['bank_statement_line_id', 'journal_entry_line_id']);
                $table->index(['bank_statement_line_id', 'accepted']);
                $table->index(['journal_entry_line_id', 'accepted']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('match_candidates');
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statements');
    }
};
