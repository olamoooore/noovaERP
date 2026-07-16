<?php

use App\Http\Controllers\Api\AuditExportController;
use App\Http\Controllers\Api\AuditorActivityLogController;
use App\Http\Controllers\Api\AuditorController;
use App\Http\Controllers\Api\AuditQueryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auditor Portal Routes
|--------------------------------------------------------------------------
| These routes handle auditor management and auditor portal access
*/

// Public routes for invitation acceptance (no auth required)
Route::middleware([
    'api',
    \App\Http\Middleware\EnsurePublicSchemaForAuth::class,
    \App\Http\Middleware\InitializeTenantForPublicAccess::class,
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
])->prefix('api/v1/auditor')->group(function () {
    Route::get('/accept/{token}', [AuditorController::class, 'acceptInvitation']);
});

// Auditor portal routes (must be registered BEFORE admin/{id} catch-all)
Route::middleware([
    'api',
    'auth:sanctum',
    \App\Http\Middleware\SetTenantFromUserPreference::class,
    \App\Http\Middleware\EnsureTenantSearchPath::class,
    \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
    \App\Http\Middleware\EnsureAuditorAccess::class,
    \App\Http\Middleware\RestrictAuditorWriteAccess::class,
])->prefix('api/v1/auditor')->group(function () {
    // Dashboard
    Route::get('/dashboard', [AuditorController::class, 'dashboard']);

    // Queries
    Route::get('/queries', [AuditQueryController::class, 'index']);
    Route::post('/queries', [AuditQueryController::class, 'store']);
    Route::get('/queries/{id}', [AuditQueryController::class, 'show']);
    Route::put('/queries/{id}', [AuditQueryController::class, 'update']);
    Route::post('/queries/{id}/respond', [AuditQueryController::class, 'respond']);
    Route::post('/queries/{id}/close', [AuditQueryController::class, 'close']);

    // Exports
    Route::get('/exports', [AuditExportController::class, 'index']);
    Route::post('/exports', [AuditExportController::class, 'store']);
    Route::get('/exports/{id}', [AuditExportController::class, 'show']);
    Route::get('/exports/{id}/download', [AuditExportController::class, 'download']);

    // Activity logs
    Route::get('/activity-logs', [AuditorActivityLogController::class, 'index']);
    Route::post('/activity-logs', [AuditorActivityLogController::class, 'store']);
    Route::get('/activity-logs/stats', [AuditorActivityLogController::class, 'stats']);

    // Profile update
    Route::patch('/profile', [AuditorController::class, 'updateProfile']);
});

// Admin query management (admin/owner can manage all queries)
Route::middleware([
    'api',
    'auth:sanctum',
    \App\Http\Middleware\SetTenantFromUserPreference::class,
    \App\Http\Middleware\EnsureTenantSearchPath::class,
    \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
    \App\Http\Middleware\RequirePermission::class.':users.write',
])->prefix('api/v1/auditor/admin')->group(function () {
    Route::get('/queries', [AuditQueryController::class, 'adminIndex']);
    Route::post('/queries', [AuditQueryController::class, 'store']);
    Route::get('/queries/{id}', [AuditQueryController::class, 'show']);
    Route::put('/queries/{id}', [AuditQueryController::class, 'update']);
    Route::post('/queries/{id}/close', [AuditQueryController::class, 'close']);
});

// Admin export management
Route::middleware([
    'api',
    'auth:sanctum',
    \App\Http\Middleware\SetTenantFromUserPreference::class,
    \App\Http\Middleware\EnsureTenantSearchPath::class,
    \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
    \App\Http\Middleware\RequirePermission::class.':users.write',
])->prefix('api/v1/auditor/admin')->group(function () {
    Route::get('/exports', [AuditExportController::class, 'adminIndex']);
    Route::post('/exports', [AuditExportController::class, 'store']);
});

// Management routes (admin/owner only) - uses {id} param, so registered LAST
Route::middleware([
    'api',
    'auth:sanctum',
    \App\Http\Middleware\SetTenantFromUserPreference::class,
    \App\Http\Middleware\EnsureTenantSearchPath::class,
    \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
    \App\Http\Middleware\RequirePermission::class.':users.write',
])->prefix('api/v1/auditor')->group(function () {
    Route::get('/', [AuditorController::class, 'index']);
    Route::post('/', [AuditorController::class, 'store']);
    Route::get('/{id}', [AuditorController::class, 'show']);
    Route::put('/{id}', [AuditorController::class, 'update']);
    Route::delete('/{id}', [AuditorController::class, 'destroy']);

    // Scope management
    Route::get('/{id}/scopes', [AuditorController::class, 'scopes']);
    Route::post('/{id}/scopes', [AuditorController::class, 'addScope']);
    Route::put('/{id}/scopes/{scopeId}', [AuditorController::class, 'updateScope']);
    Route::delete('/{id}/scopes/{scopeId}', [AuditorController::class, 'removeScope']);
});
