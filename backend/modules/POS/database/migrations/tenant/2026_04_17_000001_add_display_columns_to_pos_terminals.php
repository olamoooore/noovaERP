<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->nullable()->after('warehouse_id');
            $table->boolean('customer_display_enabled')->default(false)->after('is_active');
            $table->boolean('member_history_enabled')->default(true)->after('customer_display_enabled');
            $table->boolean('completed_promo_enabled')->default(false)->after('member_history_enabled');
            $table->unsignedBigInteger('screensaver_media_playlist_id')->nullable()->after('completed_promo_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->dropColumn([
                'store_id',
                'customer_display_enabled',
                'member_history_enabled',
                'completed_promo_enabled',
                'screensaver_media_playlist_id',
            ]);
        });
    }
};
