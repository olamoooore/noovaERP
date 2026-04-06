<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hrm_attendance_daily_summaries', function (Blueprint $table) {
            if (!Schema::hasColumn('hrm_attendance_daily_summaries', 'has_exception')) {
                $table->boolean('has_exception')->default(false)->after('status');
            }
            if (!Schema::hasColumn('hrm_attendance_daily_summaries', 'exception_type')) {
                $table->enum('exception_type', [
                    'none',
                    'missing_check_in',
                    'missing_check_out',
                    'partial_day',
                ])->default('none')->after('has_exception');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hrm_attendance_daily_summaries', function (Blueprint $table) {
            $table->dropColumn(['has_exception', 'exception_type']);
        });
    }
};
