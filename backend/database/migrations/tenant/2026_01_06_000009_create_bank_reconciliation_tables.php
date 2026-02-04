<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // bank_reconciliations table already exists, only create items table
        if (!Schema::hasTable('bank_reconciliation_items')) {
            Schema::create('bank_reconciliation_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reconciliation_id')->constrained('bank_reconciliations')->onDelete('cascade');
                $table->foreignId('journal_entry_line_id')->nullable()->constrained('journal_entry_lines');
                $table->date('transaction_date');
                $table->string('description')->nullable();
                $table->decimal('amount', 15, 2);
                $table->boolean('is_reconciled')->default(false);
                $table->timestamp('reconciled_at')->nullable();
                $table->timestamps();
                
                $table->index('reconciliation_id');
                $table->index('journal_entry_line_id');
                $table->index('is_reconciled');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_items');
    }
};
