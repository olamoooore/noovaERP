<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('exchange_rate')->constrained('analytic_accounts')->onDelete('set null');
            $table->foreignId('project_id')->nullable()->after('department_id')->constrained('analytic_accounts')->onDelete('set null');
            $table->foreignId('location_id')->nullable()->after('project_id')->constrained('analytic_accounts')->onDelete('set null');
            
            $table->index('department_id');
            $table->index('project_id');
            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropForeign(['project_id']);
            $table->dropForeign(['location_id']);
            $table->dropColumn(['department_id', 'project_id', 'location_id']);
        });
    }
};
