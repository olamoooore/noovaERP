<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_exceptions', function (Blueprint $table) {
            $table->foreignId('reconciliation_control_result_id')
                ->nullable()
                ->constrained('reconciliation_control_results')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_exceptions', function (Blueprint $table) {
            $table->dropForeign(['reconciliation_control_result_id']);
            $table->dropColumn('reconciliation_control_result_id');
        });
    }
};