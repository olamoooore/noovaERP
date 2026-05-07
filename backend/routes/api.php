<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\TenantController;

/*
|--------------------------------------------------------------------------
| Central API Routes (Non-Tenant)
|--------------------------------------------------------------------------
*/

// Lightweight connectivity ping — always returns 200 if the app is running
// Used by POS and other clients to check backend reachability (not infrastructure health)
Route::match(['get', 'head'], '/ping', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()], 200);
});

// Root health check for load balancers and CI/CD
Route::get('/health', function () {
    $checks = [];
    $healthy = true;
    $warnings = [];

    // 1. Database (central)
    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        \Illuminate\Support\Facades\DB::table('tenants')->count(); // Verify can query
        $checks['database'] = 'ok';
    } catch (\Exception $e) {
        $checks['database'] = 'fail';
        $healthy = false;
    }

    // 2. Redis
    try {
        \Illuminate\Support\Facades\Redis::connection()->ping();
        \Illuminate\Support\Facades\Redis::set('health_check_test', 'ok', 'EX', 10);
        $checks['redis'] = 'ok';
    } catch (\Exception $e) {
        $checks['redis'] = 'fail';
        $healthy = false;
    }

    // 3. Queue workers
    try {
        $queueSize = \Illuminate\Support\Facades\DB::table('jobs')->count();
        $failedJobs = \Illuminate\Support\Facades\DB::table('failed_jobs')->where('failed_at', '>', now()->subHour())->count();
        
        if ($queueSize > 1000) {
            $warnings[] = "Queue backlog: {$queueSize} jobs";
        }
        if ($failedJobs > 10) {
            $warnings[] = "Failed jobs: {$failedJobs} in last hour";
        }
        
        $checks['queue'] = [
            'status' => 'ok',
            'pending' => $queueSize,
            'failed_1h' => $failedJobs
        ];
    } catch (\Exception $e) {
        $checks['queue'] = 'fail';
        $healthy = false;
    }

    // 4. Storage
    try {
        $testFile = 'health_check_' . time() . '.txt';
        \Illuminate\Support\Facades\Storage::put($testFile, 'health check');
        $content = \Illuminate\Support\Facades\Storage::get($testFile);
        \Illuminate\Support\Facades\Storage::delete($testFile);
        
        if ($content !== 'health check') {
            throw new \Exception('Storage read/write mismatch');
        }
        
        $checks['storage'] = 'ok';
    } catch (\Exception $e) {
        $checks['storage'] = 'fail';
        $healthy = false;
    }

    // 5. Disk space
    try {
        $diskFree = disk_free_space('/');
        $diskTotal = disk_total_space('/');
        $diskPercent = round(($diskFree / $diskTotal) * 100, 2);
        
        if ($diskPercent < 10) {
            $warnings[] = "Low disk space: {$diskPercent}% free";
        }
        
        $checks['disk'] = [
            'status' => 'ok',
            'free_percent' => $diskPercent
        ];
    } catch (\Exception $e) {
        $checks['disk'] = 'unknown';
    }

    // 6. Tenant databases (sample check)
    try {
        $tenantQuery = \Illuminate\Support\Facades\DB::table('tenants');

        if (\Illuminate\Support\Facades\Schema::hasColumn('tenants', 'status')) {
            $tenantCount = $tenantQuery->where('status', 'active')->count();
        } else {
            $tenantCount = $tenantQuery->count();
        }

        $checks['tenants'] = [
            'status' => 'ok',
            'active_count' => $tenantCount
        ];
    } catch (\Exception $e) {
        $checks['tenants'] = 'fail';
        $healthy = false;
    }

    $status = $healthy ? 'ok' : 'degraded';
    if (!empty($warnings)) {
        $status = 'warning';
    }

    return response()->json([
        'status' => $status,
        'checks' => $checks,
        'warnings' => $warnings,
        'timestamp' => now()->toIso8601String(),
        'version' => config('app.version', '1.0.0'),
    ], $healthy ? 200 : 503);
});

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
// URL: /api/v1/feature-gate/* (avoids collision with tenant Api\FeatureController)
// No tenant schema switching needed — reads from public.tenant_entitlements.
Route::prefix('v1/feature-gate')->middleware([
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
