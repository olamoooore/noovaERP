<?php

use App\SuperAdmin\Http\Controllers\AdminAnalyticsController;
use App\SuperAdmin\Http\Controllers\AdminAuthController;
use App\SuperAdmin\Http\Controllers\AdminAuditLogController;
use App\SuperAdmin\Http\Controllers\AdminBillingController;
use App\SuperAdmin\Http\Controllers\AdminCollectionsController;
use App\SuperAdmin\Http\Controllers\AdminDashboardController;
use App\SuperAdmin\Http\Controllers\AdminModuleCatalogController;
use App\SuperAdmin\Http\Controllers\AdminOperationsController;
use App\SuperAdmin\Http\Controllers\AdminPlanController;
use App\SuperAdmin\Http\Controllers\AdminSubscriptionController;
use App\SuperAdmin\Http\Controllers\AdminTenantController;
use App\SuperAdmin\Http\Controllers\AdminTenantNoteController;
use App\SuperAdmin\Http\Controllers\AdminTenantOperationController;
use App\SuperAdmin\Http\Controllers\AdminTenantTimelineController;
use App\SuperAdmin\Http\Controllers\AdminUserController;
use App\SuperAdmin\Http\Middleware\DenyReadonlyRole;
use App\SuperAdmin\Http\Middleware\EnsureAdminRole;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Superadmin Control Plane Routes
|--------------------------------------------------------------------------
|
| All routes here are prefixed with /api/v1/admin (set in api.php).
| The EnsurePublicSchemaForAuth middleware is applied at the group level
| in api.php, ensuring all queries run against the public schema.
|
*/

// Public — no auth required
Route::post('/auth/login', [AdminAuthController::class, 'login']);
Route::post('/users/accept-invite', [AdminUserController::class, 'acceptInvite']);

// Protected — requires valid Sanctum token + admin role
Route::middleware(['auth:sanctum', EnsureAdminRole::class])->group(function () {
    Route::get('/auth/me',       [AdminAuthController::class, 'me']);
    Route::post('/auth/logout',  [AdminAuthController::class, 'logout']);

    Route::get('/dashboard', [AdminDashboardController::class, 'summary']);

    // --- Plan Catalog ---
    Route::prefix('plans')->group(function () {
        Route::get('/',    [AdminPlanController::class, 'index']);
        Route::post('/',   [AdminPlanController::class, 'store']);
        Route::get('/{family}',    [AdminPlanController::class, 'show']);
        Route::put('/{family}',    [AdminPlanController::class, 'update']);
        Route::delete('/{family}', [AdminPlanController::class, 'destroy']);

        Route::prefix('{family}/versions')->group(function () {
            Route::post('/',                               [AdminPlanController::class, 'createVersion']);
            Route::put('/{version}',                       [AdminPlanController::class, 'updateVersion']);
            Route::post('/{version}/prices',               [AdminPlanController::class, 'setPrices']);
            Route::post('/{version}/modules/{module}',     [AdminPlanController::class, 'attachModule']);
            Route::delete('/{version}/modules/{module}',   [AdminPlanController::class, 'detachModule']);
        });
    });

    // --- Module Catalog ---
    Route::prefix('modules')->group(function () {
        Route::get('/',         [AdminModuleCatalogController::class, 'index']);
        Route::post('/sync',    [AdminModuleCatalogController::class, 'sync']);
        Route::get('/{module}', [AdminModuleCatalogController::class, 'show']);
        Route::put('/{module}', [AdminModuleCatalogController::class, 'update']);
    });

    // --- Tenant 360 ---
    Route::prefix('tenants')->group(function () {
        // Read — all admin roles
        Route::get('/',                                [AdminTenantController::class, 'index']);
        Route::get('/{tenantId}',                      [AdminTenantController::class, 'show']);
        Route::get('/{tenantId}/operations',           [AdminTenantOperationController::class, 'index']);
        Route::get('/{tenantId}/notes',                [AdminTenantNoteController::class, 'index']);
        Route::get('/{tenantId}/health',               [AdminTenantController::class, 'latestHealth']);
        Route::get('/{tenantId}/timeline',             [AdminTenantTimelineController::class, 'index']);

        // Write — denied for support_readonly
        Route::middleware(DenyReadonlyRole::class)->group(function () {
            Route::patch('/{tenantId}/status',             [AdminTenantController::class, 'updateStatus']);
            Route::post('/{tenantId}/operations',          [AdminTenantOperationController::class, 'store']);
            Route::post('/{tenantId}/notes',               [AdminTenantNoteController::class, 'store']);
            Route::delete('/{tenantId}/notes/{note}',      [AdminTenantNoteController::class, 'destroy']);
            Route::post('/{tenantId}/health/ping',         [AdminTenantController::class, 'pingHealth']);
            Route::post('/{tenantId}/subscription',        [AdminSubscriptionController::class, 'create']);
        });

        Route::get('/{tenantId}/subscription', [AdminSubscriptionController::class, 'forTenant']);
    });

    // --- Phase 3: Subscriptions ---
    Route::prefix('subscriptions')->group(function () {
        Route::get('/',                [AdminSubscriptionController::class, 'index']);
        Route::get('/{subscription}',  [AdminSubscriptionController::class, 'show']);

        Route::middleware(DenyReadonlyRole::class)->group(function () {
            Route::patch('/{subscription}/cancel',     [AdminSubscriptionController::class, 'cancel']);
            Route::patch('/{subscription}/reactivate', [AdminSubscriptionController::class, 'reactivate']);
        });
    });

    // --- Phase 3: Billing ---
    Route::prefix('billing/invoices')->group(function () {
        Route::get('/',          [AdminBillingController::class, 'index']);
        Route::get('/{invoice}', [AdminBillingController::class, 'show']);

        Route::middleware(DenyReadonlyRole::class)->group(function () {
            Route::patch('/{invoice}/mark-paid', [AdminBillingController::class, 'markPaid']);
            Route::patch('/{invoice}/void',      [AdminBillingController::class, 'voidInvoice']);
            Route::post('/generate',             [AdminBillingController::class, 'generate']);
        });
    });

    // --- Phase 4: Audit Log + Observability ---
    // NOTE: static sub-routes (stats, export) MUST be registered before /{log}
    Route::prefix('audit-logs')->group(function () {
        Route::get('/stats',     [AdminAuditLogController::class, 'stats']);
        Route::get('/export',    [AdminAuditLogController::class, 'export']);
        Route::get('/',          [AdminAuditLogController::class, 'index']);
        Route::get('/{log}',     [AdminAuditLogController::class, 'show']);
    });

    // --- Phase 5: Admin User Management (throttled, gate-enforced per method) ---
    Route::prefix('users')->middleware('throttle:30,1')->group(function () {
        Route::get('/',                [AdminUserController::class, 'index']);
        Route::post('/invite',         [AdminUserController::class, 'invite']);
        Route::put('/{user}/role',     [AdminUserController::class, 'updateRole']);
        Route::delete('/{user}',       [AdminUserController::class, 'deactivate']);
    });

    // --- Phase 5: Collections — tracked at invoice level (throttled, gate-enforced per method) ---
    Route::prefix('collections')->middleware(['throttle:30,1', DenyReadonlyRole::class])->group(function () {
        Route::get('/',                         [AdminCollectionsController::class, 'index']);
        Route::post('/{invoice}/advance',       [AdminCollectionsController::class, 'advance']);
        Route::post('/{invoice}/clear',         [AdminCollectionsController::class, 'clear']);
    });

    // --- Phase 5: Bulk Operations ---
    Route::prefix('operations')->middleware('throttle:10,1')->group(function () {
        Route::post('/bulk-health',          [AdminOperationsController::class, 'bulkHealth']);
        Route::get('/batch/{batchId}',       [AdminOperationsController::class, 'batchStatus']);
    });

    // --- Phase 5: Revenue Analytics ---
    Route::prefix('analytics')->group(function () {
        Route::get('/revenue', [AdminAnalyticsController::class, 'revenue']);
        Route::get('/plans',   [AdminAnalyticsController::class, 'plans']);
    });
});
