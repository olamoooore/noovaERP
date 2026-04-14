<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add tenant_id to the report_cache table.
 *
 * The original report_cache table had no tenant_id column, meaning:
 *   - All tenants' cached reports lived in the same table rows
 *   - cache_key collisions across tenants were possible (MD5 of same filters = same key)
 *   - ReportCacheService::getCached() could return another tenant's report data
 *
 * This migration adds the tenant_id column and rebuilds the unique constraint
 * on cache_key to be scoped per-tenant. Existing cache rows are invalidated
 * (deleted) on migration to prevent stale cross-tenant data serving.
 *
 * Note: This runs per-tenant-schema. In a schema-per-tenant environment every
 * row already implicitly belongs to the schema-owner tenant; the tenant_id
 * column makes this explicit for query filtering and cache-key namespacing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Invalidate all existing cache entries ─────────────────────────
        // Existing rows have no tenant_id and may have been built with
        // tenant-unscoped keys. Clearing them is safer than backfilling.
        if (Schema::hasTable('report_cache')) {
            DB::table('report_cache')->truncate();
        }

        Schema::table('report_cache', function (Blueprint $table) {
            // ── 2. Add tenant_id ─────────────────────────────────────────────
            $table->string('tenant_id', 36)
                ->nullable()
                ->after('id')
                ->comment('Tenant schema owner — prevents cross-tenant cache reads');

            // ── 3. Drop the old globally-unique constraint on cache_key ──────
            $table->dropUnique(['cache_key']);

            // ── 4. Add a per-tenant unique constraint ─────────────────────────
            // Two different tenants may produce the same MD5 key from the same
            // report type + filter combination. Scope the uniqueness to (tenant_id, cache_key).
            $table->unique(['tenant_id', 'cache_key'], 'uq_report_cache_tenant_key');

            // ── 5. Add index for tenant-scoped lookups ────────────────────────
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('report_cache', function (Blueprint $table) {
            $table->dropIndex('tenant_id');
            $table->dropUnique('uq_report_cache_tenant_key');
            $table->dropColumn('tenant_id');
            $table->unique('cache_key');
        });
    }
};
