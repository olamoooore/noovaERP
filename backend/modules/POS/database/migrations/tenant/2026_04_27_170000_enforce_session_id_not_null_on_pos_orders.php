<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * TASK: POS-005 - Add NOT NULL constraint on pos_orders.session_id
     * 
     * RATIONALE:
     * - Session ownership is mandatory for POS orders
     * - Offline sync and payment guards depend on session_id being present
     * - DB-level enforcement prevents sessionless orders from being created
     * 
     * SAFETY:
     * - Preflight check counts null session_id rows
     * - Defers NOT NULL enforcement if paid legacy orders still have null session_id
     * - Rollback restores nullable state
     */
    public function up(): void
    {
        // PREFLIGHT: Check for existing null session_id rows
        $nullSessionCount = DB::table('pos_orders')
            ->whereNull('session_id')
            ->count();

        if ($nullSessionCount > 0) {
            Log::warning("Found {$nullSessionCount} orders with null session_id. Cleaning up non-paid legacy rows before constraint check.", [
                'migration' => '2026_04_27_170000_enforce_session_id_not_null_on_pos_orders',
                'null_session_count' => $nullSessionCount,
            ]);

            $deleted = DB::table('pos_orders')
                ->whereNull('session_id')
                ->whereIn('status', ['draft', 'void', 'cancelled'])
                ->delete();

            Log::info("Deleted {$deleted} non-paid orders with null session_id before enforcing constraint.");

            $remainingNull = DB::table('pos_orders')
                ->whereNull('session_id')
                ->count();

            if ($remainingNull > 0) {
                $paidNullSessionCount = DB::table('pos_orders')
                    ->whereNull('session_id')
                    ->where('status', 'paid')
                    ->count();

                Log::warning(
                    "Skipping NOT NULL enforcement for pos_orders.session_id because legacy orders still have null session_id.",
                    [
                        'migration' => '2026_04_27_170000_enforce_session_id_not_null_on_pos_orders',
                        'remaining_null_session_count' => $remainingNull,
                        'paid_null_session_count' => $paidNullSessionCount,
                    ]
                );

                return;
            }
        }

        // Add NOT NULL constraint
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('session_id')->nullable(false)->change();
        });

        Log::info('Successfully enforced NOT NULL constraint on pos_orders.session_id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore nullable state
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('session_id')->nullable()->change();
        });

        Log::info('Reverted pos_orders.session_id to nullable');
    }
};
