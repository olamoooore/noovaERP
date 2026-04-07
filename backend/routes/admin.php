<?php

use App\SuperAdmin\Http\Controllers\AdminAuthController;
use App\SuperAdmin\Http\Controllers\AdminBillingController;
use App\SuperAdmin\Http\Controllers\AdminDashboardController;
use App\SuperAdmin\Http\Controllers\AdminModuleCatalogController;
use App\SuperAdmin\Http\Controllers\AdminPlanController;
use App\SuperAdmin\Http\Controllers\AdminSubscriptionController;
use App\SuperAdmin\Http\Controllers\AdminTenantController;
use App\SuperAdmin\Http\Controllers\AdminTenantNoteController;
use App\SuperAdmin\Http\Controllers\AdminTenantOperationController;
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

// Protected — requires valid Sanctum token + admin role
Route::middleware(['auth:sanctum', EnsureAdminRole::class])->group(function () {
    Route::get('/auth/me', [AdminAuthController::class, 'me']);
    Route::post('/auth/logout', [AdminAuthController::class, 'logout']);

    Route::get('/dashboard', [AdminDashboardController::class, 'summary']);

    // --- Plan Catalog ---
    Route::prefix('plans')->group(function () {
        Route::get('/',    [AdminPlanController::class, 'index']);
        Route::post('/',   [AdminPlanController::class, 'store']);
        Route::get('/{family}',    [AdminPlanController::class, 'show']);
        Route::put('/{family}',    [AdminPlanController::class, 'update']);

        Route::prefix('{family}/versions')->group(function () {
            Route::post('/',                           [AdminPlanController::class, 'createVersion']);
            Route::put('/{version}',                   [AdminPlanController::class, 'updateVersion']);
            Route::post('/{version}/prices',           [AdminPlanController::class, 'setPrices']);
            Route::post('/{version}/modules/{module}', [AdminPlanController::class, 'attachModule']);
            Route::delete('/{version}/modules/{module}', [AdminPlanController::class, 'detachModule']);
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
        Route::get('/',                              [AdminTenantController::class, 'index']);
        Route::get('/{tenantId}',                    [AdminTenantController::class, 'show']);
        Route::get('/{tenantId}/operations',         [AdminTenantOperationController::class, 'index']);
        Route::get('/{tenantId}/notes',              [AdminTenantNoteController::class, 'index']);
        Route::get('/{tenantId}/health',             [AdminTenantController::class, 'latestHealth']);

        // Write — denied for support_readonly
        Route::middleware(DenyReadonlyRole::class)->group(function () {
            Route::patch('/{tenantId}/status',           [AdminTenantController::class, 'updateStatus']);
            Route::post('/{tenantId}/operations',        [AdminTenantOperationController::class, 'store']);
            Route::post('/{tenantId}/notes',             [AdminTenantNoteController::class, 'store']);
            Route::delete('/{tenantId}/notes/{note}',    [AdminTenantNoteController::class, 'destroy']);
            Route::post('/{tenantId}/health/ping',       [AdminTenantController::class, 'pingHealth']);
            Route::post('/{tenantId}/subscription',      [AdminSubscriptionController::class, 'create']);
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
});
