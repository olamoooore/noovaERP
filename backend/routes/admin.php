<?php

use App\SuperAdmin\Http\Controllers\AdminAuthController;
use App\SuperAdmin\Http\Controllers\AdminDashboardController;
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
});
