<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Bank Reconciliations table for tracking bank statement reconciliation
     */
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->date('statement_date');
            $table->decimal('statement_balance', 15, 2);
            $table->decimal('opening_balance', 15, 2);
            $table->decimal('closing_balance', 15, 2);
            $table->decimal('difference', 15, 2)->default(0);
            $table->enum('status', ['draft', 'completed'])->default('draft');
            $table->foreignId('reconciled_by')->nullable()->constrained('users');
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            $table->index('bank_account_id');
            $table->index('statement_date');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
    }
};
