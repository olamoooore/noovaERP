<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RM-003: Semantically separate non-product reward quota from physical stock.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('loyalty_rewards', 'reward_quota_quantity')) {
            Schema::table('loyalty_rewards', function (Blueprint $table) {
                $table->integer('reward_quota_quantity')
                    ->nullable()
                    ->after('points_cost')
                    ->comment('Quota for non-product rewards; NULL = unlimited');
            });
        }

        // Backfill from legacy stock_quantity so existing behavior is preserved.
        if (Schema::hasColumn('loyalty_rewards', 'stock_quantity')) {
            DB::table('loyalty_rewards')
                ->whereNull('reward_quota_quantity')
                ->update([
                    'reward_quota_quantity' => DB::raw('stock_quantity'),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('loyalty_rewards', 'reward_quota_quantity')) {
            Schema::table('loyalty_rewards', function (Blueprint $table) {
                $table->dropColumn('reward_quota_quantity');
            });
        }
    }
};
