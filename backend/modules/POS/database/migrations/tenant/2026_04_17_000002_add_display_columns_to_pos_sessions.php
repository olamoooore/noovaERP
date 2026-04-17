<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->string('order_reference')->nullable()->after('session_number');
            $table->string('current_display_state', 50)->default('IDLE_SCREENSAVER')->after('status');
            $table->unsignedBigInteger('member_id')->nullable()->after('current_display_state');
            $table->unsignedBigInteger('focused_line_id')->nullable()->after('member_id');
            $table->timestamp('last_activity_at')->nullable()->after('opened_at');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'order_reference',
                'current_display_state',
                'member_id',
                'focused_line_id',
                'last_activity_at',
            ]);
        });
    }
};
