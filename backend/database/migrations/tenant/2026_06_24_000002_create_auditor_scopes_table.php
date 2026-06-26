<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditor_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auditor_id')->constrained('auditors')->onDelete('cascade');
            $table->foreignId('entity_id')->nullable()->constrained('legal_entities')->onDelete('cascade');
            $table->string('accounting_period', 50)->nullable();
            $table->json('modules')->nullable(); // ["accounting", "reports", "financial_close"]
            $table->json('reports')->nullable(); // ["profit_loss", "balance_sheet", "trial_balance", "general_ledger", "ar_aging", "ap_aging", "tax_reports"]
            $table->json('transactions')->nullable(); // ["invoices", "bills", "journals", "payments", "reconciliation"]
            $table->boolean('can_export')->default(false);
            $table->boolean('can_raise_queries')->default(true);
            $table->boolean('can_view_documents')->default(true);
            $table->timestamps();

            $table->index(['auditor_id', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditor_scopes');
    }
};
