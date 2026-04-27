<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * TASK: ACC-002 - Store tax identity on GL journal entry lines
     * 
     * RATIONALE:
     * - GL lines must store tax identity for tax audit trail
     * - Enables tax_transaction_details writer to link GL to tax reporting
     * - Required for tax reconciliation and compliance reporting
     */
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->string('tax_code', 50)->nullable()->after('description');
            $table->unsignedBigInteger('tax_id')->nullable()->after('tax_code');
            
            $table->foreign('tax_id')
                  ->references('id')
                  ->on('taxes')
                  ->onDelete('set null');
            
            $table->index('tax_code');
            $table->index('tax_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropForeign(['tax_id']);
            $table->dropIndex(['tax_code']);
            $table->dropIndex(['tax_id']);
            $table->dropColumn(['tax_code', 'tax_id']);
        });
    }
};
