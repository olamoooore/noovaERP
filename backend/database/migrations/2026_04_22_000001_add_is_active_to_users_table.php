<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add deactivated_at timestamp and a composite index to the users table.
     * is_active already exists on this table — we only add what's missing.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (Schema::hasColumn('users', 'is_active') && ! Schema::hasColumn('users', 'deactivated_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('deactivated_at')->nullable();
            });
        }

        if (Schema::hasColumn('users', 'role') && Schema::hasColumn('users', 'is_active')) {
            // PostgreSQL supports IF NOT EXISTS for index creation.
            DB::statement('CREATE INDEX IF NOT EXISTS users_role_is_active_idx ON users (role, is_active)');
        }

        if (Schema::hasColumn('users', 'is_active') && Schema::hasColumn('users', 'deactivated_at')) {
            // Backfill: users with is_active = false but no deactivated_at timestamp.
            DB::statement('
                UPDATE users
                SET deactivated_at = updated_at
                WHERE is_active = false AND deactivated_at IS NULL
            ');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS users_role_is_active_idx');

        if (Schema::hasColumn('users', 'deactivated_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('deactivated_at');
            });
        }
    }
};
