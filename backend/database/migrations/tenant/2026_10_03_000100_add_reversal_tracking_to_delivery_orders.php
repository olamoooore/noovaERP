<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #463 (DO11): a reversed delivery order (stock restored, COGS reversed)
 * shared status 'cancelled' with a never-shipped cancellation, distinguishable
 * only by a "[REVERSED]" note in free text. Track the reversal explicitly so
 * the list and detail can show it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_orders')) {
            return;
        }

        Schema::table('delivery_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('delivery_orders', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('completed_at');
            }
            if (! Schema::hasColumn('delivery_orders', 'reversal_reason')) {
                $table->text('reversal_reason')->nullable()->after('reversed_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('delivery_orders')) {
            return;
        }

        Schema::table('delivery_orders', function (Blueprint $table) {
            foreach (['reversed_at', 'reversal_reason'] as $column) {
                if (Schema::hasColumn('delivery_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
