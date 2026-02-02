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

Route::middleware([\App\Http\Middleware\CorsMiddleware::class])->prefix('auth')->group(function () {
    // Tenant signup & management
    Route::post('/signup', [TenantController::class, 'signup']);
    
    // Temporary non-tenant login for development
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    
    // Health check
    Route::get('/health', fn() => response()->json(['status' => 'ok']));
});
