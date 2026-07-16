<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hrm_payroll_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('journal_entry_id')->nullable()->after('notes');

            if (Schema::hasTable('journal_entries')) {
                $table->foreign('journal_entry_id')
                    ->references('id')
                    ->on('journal_entries')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('hrm_payroll_runs', function (Blueprint $table) {
            if (Schema::hasTable('journal_entries')) {
                $table->dropForeign(['journal_entry_id']);
            }
            $table->dropColumn('journal_entry_id');
        });
    }
};
