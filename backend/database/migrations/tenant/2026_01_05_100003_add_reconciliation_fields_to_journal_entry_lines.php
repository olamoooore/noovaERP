<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add reconciliation tracking fields to journal_entry_lines table
     */
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->boolean('reconciled')->default(false)->after('reference');
            $table->foreignId('reconciliation_id')->nullable()->after('reconciled')->constrained('bank_reconciliations')->onDelete('set null');
            $table->date('reconciliation_date')->nullable()->after('reconciliation_id');

            $table->index('reconciled');
            $table->index('reconciliation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropForeign(['reconciliation_id']);
            $table->dropIndex(['reconciled']);
            $table->dropIndex(['reconciliation_id']);
            $table->dropColumn(['reconciled', 'reconciliation_id', 'reconciliation_date']);
        });
    }
};
