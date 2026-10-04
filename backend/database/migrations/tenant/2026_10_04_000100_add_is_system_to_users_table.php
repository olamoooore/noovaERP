<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #878 (SA4): mark the per-tenant dedicated system actor used for
 * non-interactive (queued / scheduled / webhook / console) postings.
 *
 * At most one system actor may exist per tenant schema — enforced by a
 * partial unique index so two concurrent workers cannot both provision one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_system')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_system')->default(false)->after('is_active');
            });
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS users_single_system_actor_idx '.
                'ON users (is_system) WHERE is_system = true'
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS users_single_system_actor_idx');
        }

        if (Schema::hasColumn('users', 'is_system')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_system');
            });
        }
    }
};
