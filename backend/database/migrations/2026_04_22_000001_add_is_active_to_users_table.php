<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add deactivated_at timestamp and a composite index to the users table.
     * is_active already exists on this table — we only add what's missing.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'deactivated_at')) {
                $table->timestamp('deactivated_at')->nullable()->after('is_active');
            }

            // Composite index for admin user queries filtered by role + active state
            $table->index(['role', 'is_active'], 'users_role_is_active_idx');
        });

        // Backfill: users with is_active = false but no deactivated_at timestamp
        DB::statement("
            UPDATE users
            SET deactivated_at = updated_at
            WHERE is_active = false AND deactivated_at IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_role_is_active_idx');

            if (Schema::hasColumn('users', 'deactivated_at')) {
                $table->dropColumn('deactivated_at');
            }
        });
    }
};
