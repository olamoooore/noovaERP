<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_action_logs', function (Blueprint $table) {
            // severity: info (default) | warning | critical
            $table->string('severity', 20)->default('info')->after('status');
            // pre-computed human sentence stored at write time
            $table->text('human_description')->nullable()->after('severity');

            // Additional targeted indices for the query patterns used in the controller
            $table->index('severity');
            $table->index('admin_user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('admin_action_logs', function (Blueprint $table) {
            $table->dropIndex(['severity']);
            $table->dropIndex(['admin_user_id']);
            $table->dropIndex(['created_at']);
            $table->dropColumn(['severity', 'human_description']);
        });
    }
};
