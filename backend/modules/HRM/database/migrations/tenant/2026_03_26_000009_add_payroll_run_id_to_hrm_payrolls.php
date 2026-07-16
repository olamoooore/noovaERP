<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hrm_payrolls', function (Blueprint $table) {
            $table->foreignId('payroll_run_id')
                ->nullable()
                ->after('id')
                ->constrained('hrm_payroll_runs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hrm_payrolls', function (Blueprint $table) {
            $table->dropForeign(['payroll_run_id']);
            $table->dropColumn('payroll_run_id');
        });
    }
};
