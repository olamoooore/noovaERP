<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #407 (SALES-ODOO): lock/unlock confirmed sales orders.
 *
 * Odoo locks a confirmed order to protect it from edits, and unlocks it to
 * amend it. NoovaERP had no such state — `PUT /sales/orders/{id}` rejected
 * every non-draft order, so a confirmed order could only be cancelled or
 * re-created.
 *
 * Additive only: every existing row defaults to unlocked, so confirmation
 * keeps its current, amendable behaviour until a tenant or a user locks the
 * order (or enables `sales.orders.auto_lock_on_confirm`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_orders') || Schema::hasColumn('sales_orders', 'is_locked')) {
            return;
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false)->after('status');
            $table->timestamp('locked_at')->nullable()->after('is_locked');
            $table->foreignId('locked_by')->nullable()->after('locked_at')
                ->constrained('users')->nullOnDelete();
            $table->index('is_locked');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_orders') || ! Schema::hasColumn('sales_orders', 'is_locked')) {
            return;
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeign(['locked_by']);
            $table->dropIndex(['is_locked']);
            $table->dropColumn(['is_locked', 'locked_at', 'locked_by']);
        });
    }
};
