<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('loyalty_redemptions', function (Blueprint $table) {
            $table->string('type', 20)->after('loyalty_transaction_id')->nullable();
            // type can be: 'reward' or 'discount'
        });

        // Update existing records
        DB::table('loyalty_redemptions')
            ->whereNull('type')
            ->update([
                'type' => DB::raw("CASE WHEN loyalty_reward_id IS NOT NULL THEN 'reward' ELSE 'discount' END")
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loyalty_redemptions', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
