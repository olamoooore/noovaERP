<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\TenantController;

/*
|--------------------------------------------------------------------------
| Central API Routes (Non-Tenant)
|--------------------------------------------------------------------------
*/

// Root health check for load balancers and CI/CD
Route::get('/health', fn() => response()->json([
    'status' => 'ok',
    'timestamp' => now()->toIso8601String(),
    'version' => config('app.version', '1.0.0'),
]));

// V1 Auth routes (central, non-tenant)
Route::prefix('v1/auth')->middleware([\App\Http\Middleware\EnsurePublicSchemaForAuth::class])->group(function () {
    // Tenant signup & management
    Route::post('/signup', [TenantController::class, 'signup']);
    
    // Central login (non-tenant)
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    
    // Health check
    Route::get('/health', fn() => response()->json(['status' => 'ok']));
});

// Superadmin Control Plane routes
// SubstituteBindings is added AFTER EnsurePublicSchemaForAuth so that
// route-model-binding queries run against the public schema, not a tenant schema.
Route::prefix('v1/admin')->middleware([
    \App\Http\Middleware\EnsurePublicSchemaForAuth::class,
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
])->group(function () {
    require __DIR__.'/admin.php';
});

// ── Phase 7: Runtime Feature Gate API ──────────────────────────────────────
// Called by ERP modules at runtime to check entitlements.
// Auth: Authorization: Bearer ten_{key}
// No tenant schema switching needed — reads from public.tenant_entitlements.
Route::prefix('v1/features')->middleware([
    \App\Http\Middleware\EnsurePublicSchemaForAuth::class,
    'auth.tenant_key',
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
    'throttle:120,1',    // 120 requests per minute per key
])->group(function () {
    require __DIR__.'/feature-gate.php';
});

// Legacy auth routes (keep for backwards compatibility)
Route::prefix('auth')->group(function () {
    Route::post('/signup', [TenantController::class, 'signup']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::get('/health', fn() => response()->json(['status' => 'ok']));
});
