<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reconciliation Control Results
        Schema::create('reconciliation_control_results', function (Blueprint $table) {
            $table->id();
            $table->string('control_name', 100); // e.g., 'ar_gl_reconciliation', 'ap_gl_reconciliation'
            $table->date('as_of_date');
            $table->decimal('subledger_balance', 15, 2)->default(0);
            $table->decimal('gl_balance', 15, 2)->default(0);
            $table->decimal('difference', 15, 2)->default(0);
            $table->string('status', 20)->default('pending'); // passed, failed, warning
            $table->json('discrepancies')->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['control_name', 'as_of_date']);
            $table->index('status');
            $table->index('as_of_date');
        });

        // Reconciliation Exceptions
        Schema::create('reconciliation_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('exception_type', 50); // unmatched_transaction, bank_error, etc.
            $table->foreignId('bank_account_id')->nullable()->constrained('chart_of_accounts');
            $table->decimal('amount', 15, 2)->nullable();
            $table->date('transaction_date')->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('aging_bucket', 20)->default('current'); // current, 1_30_days, 31_60_days, 61_90_days, 90_plus_days
            $table->integer('days_open')->default(0);
            $table->string('status', 30)->default('open'); // open, in_progress, pending_approval, resolved, escalated
            $table->string('priority', 20)->default('medium'); // low, medium, high, critical
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamps();

            // Indexes
            $table->index('exception_type');
            $table->index('status');
            $table->index('aging_bucket');
            $table->index('priority');
            $table->index('assigned_to');
            $table->index('created_at');
            $table->index(['status', 'aging_bucket']);
        });

        // Physical Inventory Counts (for inventory reconciliation)
        Schema::create('physical_inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->decimal('physical_quantity', 15, 3);
            $table->decimal('system_quantity', 15, 3);
            $table->decimal('variance', 15, 3);
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->decimal('variance_value', 15, 2)->nullable();
            $table->date('count_date');
            $table->string('counted_by', 100);
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('pending'); // pending, approved, posted
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained();
            $table->timestamps();

            // Indexes
            $table->index(['product_id', 'warehouse_id']);
            $table->index('count_date');
            $table->index('status');
            $table->unique(['product_id', 'warehouse_id', 'count_date'], 'unique_physical_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_inventory_counts');
        Schema::dropIfExists('reconciliation_exceptions');
        Schema::dropIfExists('reconciliation_control_results');
    }
};
