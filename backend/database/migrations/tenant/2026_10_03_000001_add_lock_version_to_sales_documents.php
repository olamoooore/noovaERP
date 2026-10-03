<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #896 (SALES-PLAT / PC2) — optimistic concurrency for Sales document
 * updates.
 *
 * `updated_at` alone cannot act as a version token on PostgreSQL: Laravel's
 * `timestamps()` creates `timestamp(0)` columns, so two updates within the
 * same second are indistinguishable. `lock_version` is a monotonically
 * increasing integer bumped by every successful update through the real HTTP
 * routes; clients echo it back as `version`, `updated_at` or `If-Match` and a
 * mismatch returns 409 instead of silently overwriting the other user's work.
 *
 * Backfill: `ADD COLUMN ... DEFAULT 1` populates every existing row in
 * PostgreSQL as part of the DDL — no separate backfill script is required.
 */
return new class extends Migration
{
    private const TABLES = ['invoices', 'quotations', 'sales_orders'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'lock_version')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedInteger('lock_version')->default(1);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'lock_version')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('lock_version');
            });
        }
    }
};
