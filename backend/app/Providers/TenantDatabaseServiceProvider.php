<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * RETIRED — MT-05 search_path consolidation (2026-03-31).
 *
 * This provider previously used DB::listen() to set search_path on new
 * connections after the first query.  That approach is fundamentally too
 * late: the first query on a fresh connection runs in the wrong schema
 * before the listener fires.
 *
 * Search-path is now enforced by two mechanisms only:
 *   1. SetTenantSearchPath listener (on TenancyInitialized event) — primary.
 *   2. EnsureTenantSearchPath middleware — belt-and-suspenders per request.
 *
 * The class is kept as a registered no-op so the cached provider manifest
 * does not break until the next `php artisan optimize:clear`.
 */
class TenantDatabaseServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void {}
}
