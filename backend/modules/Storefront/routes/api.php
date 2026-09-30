<?php

use App\Http\Middleware\RequirePermission;
use Illuminate\Support\Facades\Route;
use Modules\Storefront\Http\Controllers\Admin\OfflinePaymentController;
use Modules\Storefront\Http\Controllers\Admin\OrderController;
use Modules\Storefront\Http\Controllers\Admin\PromotionController;
use Modules\Storefront\Http\Controllers\Admin\ReportController;
use Modules\Storefront\Http\Controllers\Admin\ReturnController;
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

        // Staff order console (FR-ADM-01). Status writes go through the
        // shared OrderStateMachine — no side door (FR-ORD-01).
        Route::get('/orders', [OrderController::class, 'index'])
            ->middleware(RequirePermission::using('storefront.view'));

        Route::get('/orders/{order}', [OrderController::class, 'show'])
            ->whereNumber('order')
            ->middleware(RequirePermission::using('storefront.view'));

        Route::post('/orders/{order}/status', [OrderController::class, 'updateStatus'])
            ->whereNumber('order')
            ->middleware(RequirePermission::using('storefront.write'));

        // Platform-initiated refund entry (FR-ORD-04 / PAY-R10, A-18 platform
        // direction): records + reverses through the shared PaymentRefund
        // Service; the gateway-issuing direction runs on the platform payments
        // endpoint, which delegates here via the refund-handler seam.
        Route::post('/orders/{order}/refund', [OrderController::class, 'refund'])
            ->whereNumber('order')
            ->middleware(RequirePermission::using('storefront.write'));

        // Partial shipment + tracking (FR-ORD-05); logistics only.
        Route::post('/orders/{order}/fulfil', [OrderController::class, 'fulfil'])
            ->whereNumber('order')
            ->middleware(RequirePermission::using('storefront.write'));

        // Offline settlement review (PAY-R13 flow 5): approve drives the SAME
        // canonical completion chain as a gateway webhook; reject returns the
        // order to pending. Proof files are private (bank evidence).
        Route::post('/orders/{order}/offline-payment', [OfflinePaymentController::class, 'review'])
            ->whereNumber('order')
            ->middleware(RequirePermission::using('storefront.write'));

        Route::get('/orders/{order}/proofs/{proof}/file', [OfflinePaymentController::class, 'file'])
            ->whereNumber('order')->whereNumber('proof')
            ->middleware(RequirePermission::using('storefront.write'));

        // Promotions (FR-CO-09/FR-ADM-01): definitions are merchant data;
        // the discount math lives only in StorefrontPromotionService.
        Route::get('/promotions', [PromotionController::class, 'index'])
            ->middleware(RequirePermission::using('storefront.view'));

        Route::post('/promotions', [PromotionController::class, 'store'])
            ->middleware(RequirePermission::using('storefront.write'));

        Route::put('/promotions/{promotion}', [PromotionController::class, 'update'])
            ->whereNumber('promotion')
            ->middleware(RequirePermission::using('storefront.write'));

        Route::get('/promotions/{promotion}/redemptions', [PromotionController::class, 'redemptions'])
            ->whereNumber('promotion')
            ->middleware(RequirePermission::using('storefront.view'));

        // Return desk (FR-ORD-06): request -> approve|reject -> receive ->
        // refund|close. Money leg = canonical refunds; stock leg = canonical
        // inventory; both engine-journaled.
        Route::prefix('returns')->group(function () {
            Route::get('/', [ReturnController::class, 'index'])
                ->middleware(RequirePermission::using('storefront.view'));
            Route::post('/{return}/approve', [ReturnController::class, 'approve'])
                ->whereNumber('return')->middleware(RequirePermission::using('storefront.write'));
            Route::post('/{return}/reject', [ReturnController::class, 'reject'])
                ->whereNumber('return')->middleware(RequirePermission::using('storefront.write'));
            Route::post('/{return}/receive', [ReturnController::class, 'receive'])
                ->whereNumber('return')->middleware(RequirePermission::using('storefront.write'));
            Route::post('/{return}/refund', [ReturnController::class, 'refund'])
                ->whereNumber('return')->middleware(RequirePermission::using('storefront.write'));
            Route::post('/{return}/close', [ReturnController::class, 'close'])
                ->whereNumber('return')->middleware(RequirePermission::using('storefront.write'));
        });

        // Reporting + ops (FR-ADM-06): read-only, tenant-scoped; ?format=csv
        // exports the same aggregation.
        Route::prefix('reports')->group(function () {
            Route::get('/sales', [ReportController::class, 'sales'])
                ->middleware(RequirePermission::using('storefront.view'));
            Route::get('/tax', [ReportController::class, 'tax'])
                ->middleware(RequirePermission::using('storefront.view'));
            Route::get('/reconciliation', [ReportController::class, 'reconciliation'])
                ->middleware(RequirePermission::using('storefront.view'));
            Route::get('/funnel', [ReportController::class, 'funnel'])
                ->middleware(RequirePermission::using('storefront.view'));
            Route::get('/overview', [ReportController::class, 'overview'])
                ->middleware(RequirePermission::using('storefront.view'));
        });
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
