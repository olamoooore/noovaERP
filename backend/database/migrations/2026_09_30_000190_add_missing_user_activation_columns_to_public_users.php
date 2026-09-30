<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reproducibility fix — public.users.is_active (and deactivated_at) exist
 * on production but were never created by any migration: the earlier
 * 2026_04_22 migration only backfills when the columns ALREADY exist
 * ("is_active already exists on this table"), so a fresh database ends up
 * without them and EnsureAdminRole reads null -> treats every platform
 * admin as deactivated (403) — the SuperAdmin console cannot authenticate
 * against a clean install.
 *
 * Guarded no-op on databases that already have the columns (production).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (! Schema::hasColumn('users', 'deactivated_at')) {
                $table->timestamp('deactivated_at')->nullable();
            }
        });

        if (Schema::hasColumn('users', 'role') && Schema::hasColumn('users', 'is_active')) {
            DB::statement('CREATE INDEX IF NOT EXISTS users_role_is_active_idx ON users (role, is_active)');
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
        if (Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }
    }
};
