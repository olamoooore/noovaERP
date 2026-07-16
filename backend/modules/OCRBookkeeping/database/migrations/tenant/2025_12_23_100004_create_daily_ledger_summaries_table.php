<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily Ledger Summaries Table
     * Pre-calculated aggregated financial data for performance-optimized BI reporting
     * Updated nightly or when journal entries are posted
     */
    public function up(): void
    {
        Schema::create('daily_ledger_summaries', function (Blueprint $table) {
            $table->id();

            // Date for the summary
            $table->date('summary_date');

            // Account information
            $table->foreignId('account_id')->constrained('chart_of_accounts')->onDelete('cascade');
            $table->string('account_type', 50); // asset, liability, equity, revenue, expense
            $table->string('account_category', 50)->nullable(); // current_asset, fixed_asset, etc.

            // Aggregated amounts
            $table->decimal('total_debit', 20, 2)->default(0);
            $table->decimal('total_credit', 20, 2)->default(0);
            $table->decimal('net_amount', 20, 2)->default(0); // debit - credit
            $table->integer('transaction_count')->default(0);

            // Dimensions for analytical reporting (JSONB)
            $table->jsonb('dimensions')->nullable();

            // Running balance
            $table->decimal('running_balance', 20, 2)->default(0);

            // Metadata
            $table->timestamp('calculated_at')->useCurrent();
            $table->timestamps();

            // Indexes for performance
            $table->index('summary_date');
            $table->index('account_id');
            $table->index('account_type');
            $table->index('account_category');
            $table->index(['summary_date', 'account_id']); // Composite index for common queries

            // GIN index for dimensions JSONB field
            $table->index('dimensions', 'idx_daily_ledger_summaries_dimensions', 'gin');

            // Unique constraint to prevent duplicate summaries
            $table->unique(['summary_date', 'account_id', 'dimensions'], 'unique_daily_summary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_ledger_summaries');
    }
};
