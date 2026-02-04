<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Reconciliation Items table for linking transactions to reconciliations
     */
    public function up(): void
    {
        Schema::create('reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained('bank_reconciliations')->onDelete('cascade');
            $table->foreignId('journal_entry_line_id')->constrained('journal_entry_lines')->onDelete('restrict');
            $table->timestamps();

            $table->index('reconciliation_id');
            $table->index('journal_entry_line_id');
            
            // Ensure a transaction can only be reconciled once
            $table->unique('journal_entry_line_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reconciliation_items');
    }
};
