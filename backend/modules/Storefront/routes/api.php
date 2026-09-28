<?php

use App\Http\Middleware\RequirePermission;
use Illuminate\Support\Facades\Route;
use Modules\Storefront\Http\Controllers\Admin\SettingsController;
use Modules\Storefront\Http\Controllers\Editor\BlockController;
use Modules\Storefront\Http\Controllers\Editor\PreviewController;

// Storefront tenant API routes.
//
// This file is required by ModuleManager INSIDE the authenticated tenant
// group of routes/tenant.php (prefix api/v1; chain: auth:sanctum ->
// Impersonation -> SetTenantFromUserPreference -> EnsureTenantSearchPath ->
// SyncTenantUserFromCentralAuth). Do NOT re-wrap with ['api','tenant'] —
// that is the issue #143 crash pattern.
//
// Every route additionally carries the module entitlement gate
// (entitlement:storefront, FR-MOD-02) and exact RequirePermission strings
// seeded in RolePermissionSeeder::PERMISSION_MATRIX (FR-MOD-03).
// Public storefront runtime routes land in routes/tenant.php's public group
// in P1 (Appendix E §E6 convention) — nothing tenant-facing exists yet.

Route::middleware(['auth:sanctum', 'entitlement:storefront'])
    ->prefix('admin/storefront')
    ->group(function () {
        Route::get('/settings', [SettingsController::class, 'show'])
            ->middleware(RequirePermission::using('storefront.view'));

        Route::put('/settings', [SettingsController::class, 'update'])
            ->middleware(RequirePermission::using('storefront.settings'));
    });

// WebsiteBuilder editor integration (Appendix E §E4.3). Paths live under
// the website-builder prefix so the editor calls them beside its own
// endpoints; access still requires the storefront entitlement + view perm.
Route::middleware(['auth:sanctum', 'entitlement:storefront'])
    ->prefix('website-builder/storefront')
    ->group(function () {
        Route::get('/blocks', BlockController::class.'@index')
            ->middleware(RequirePermission::using('storefront.view'));

        Route::post('/preview', PreviewController::class)
            ->middleware(RequirePermission::using('storefront.view'));
    });
