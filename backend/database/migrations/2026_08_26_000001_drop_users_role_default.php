<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Issue #P2-1 — Pillar 2 Part 1 §3.4 / Pillar 1 P0-0:
 *
 * The `users.role` column (introduced by migration
 * 2026_04_03_000001_add_role_to_users_table) had a schema-level default of
 * `'platform_super_admin'` — the same string that RequirePermission,
 * EnsureAdminRole, AdminAuthController, and four other authorization
 * locations read as the global bypass. Any code path that inserted a
 * `users` row without explicitly setting `role` would silently receive
 * the SuperAdmin bypass (TenantUserController::invite() did exactly this
 * at line 256 of `app/Http/Controllers/Api/TenantUserController.php`).
 *
 * This migration drops the default AND makes the column nullable, so
 * fresh inserts without an explicit `role` get NULL — and
 * `User::isSuperAdmin()` evaluates to `false` for NULL.
 *
 * Existing rows are NOT modified (per the report's "do not lock out
 * legitimate SuperAdmins" caveat). Existing legit SuperAdmin accounts
 * retain `'platform_super_admin'`, and existing compromised accounts
 * retain the bypass — but the new schema default prevents future
 * insertions from silently granting it.
 *
 * This migration is idempotent: it always issues the `ALTER COLUMN`
 * statements (DROP DEFAULT / SET NOT NULL → SET DEFAULT NULL / DROP NOT NULL),
 * guarded by information_schema reads so a second run is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent guard: if `users` doesn't exist yet, skip.
        $hasTable = DB::scalar(
            "SELECT 1 FROM information_schema.tables WHERE table_name = 'users' LIMIT 1"
        );
        if (! $hasTable) {
            return;
        }

        $isNullable = DB::scalar(
            "SELECT is_nullable FROM information_schema.columns
             WHERE table_name = 'users' AND column_name = 'role' LIMIT 1"
        );

        // Make the column nullable so NULL is a legal value.
        // ALTER TABLE ... ALTER COLUMN ... DROP NOT NULL is idempotent in
        // Postgres — if the column is already nullable, it's a no-op.
        if ($isNullable === 'NO') {
            DB::statement('ALTER TABLE users ALTER COLUMN role DROP NOT NULL');
        }

        // Drop the legacy schema default.
        DB::statement('ALTER TABLE users ALTER COLUMN role DROP DEFAULT');
    }

    public function down(): void
    {
        // Restore: bring back the 'platform_super_admin' default and NOT NULL.
        // Order matters — default value has to be set BEFORE adding NOT NULL.
        DB::statement(
            "ALTER TABLE users ALTER COLUMN role SET DEFAULT 'platform_super_admin'::character varying"
        );
        DB::statement('ALTER TABLE users ALTER COLUMN role SET NOT NULL');
    }
};

