<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a composite index on (admin_user_id, created_at) to speed up
     * audit log queries filtered by a specific admin user over a date range.
     *
     * The existing single-column indices on admin_user_id and created_at
     * are less efficient for the combined filter pattern used in the controller.
     */
    public function up(): void
    {
        Schema::table('admin_action_logs', function (Blueprint $table) {
            $table->index(['admin_user_id', 'created_at'], 'admin_action_logs_user_date_idx');
            $table->index(['status', 'created_at'], 'admin_action_logs_status_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('admin_action_logs', function (Blueprint $table) {
            $table->dropIndex('admin_action_logs_user_date_idx');
            $table->dropIndex('admin_action_logs_status_date_idx');
        });
    }
};
