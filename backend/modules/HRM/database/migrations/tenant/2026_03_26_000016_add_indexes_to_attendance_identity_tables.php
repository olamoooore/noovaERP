<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hrm_attendance_events', function (Blueprint $table) {
            $table->index('status');
            $table->index('event_type');
        });

        Schema::table('hrm_attendance_daily_summaries', function (Blueprint $table) {
            $table->index('status');
            $table->index('has_exception');
        });

        Schema::table('hrm_employee_user_links', function (Blueprint $table) {
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('hrm_attendance_events', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['event_type']);
        });

        Schema::table('hrm_attendance_daily_summaries', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['has_exception']);
        });

        Schema::table('hrm_employee_user_links', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
    }
};
