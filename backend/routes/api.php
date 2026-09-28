<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\ProfileController;
use App\Http\Controllers\Api\TenantController;
use Illuminate\Support\Facades\Route;

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
            'failed_1h' => $failedJobs,
        ];
    } catch (\Exception $e) {
        $checks['queue'] = 'fail';
        $healthy = false;
    }

    // 4. Storage
    try {
        $testFile = 'health_check_'.time().'.txt';
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
            'free_percent' => $diskPercent,
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
            'active_count' => $tenantCount,
        ];
    } catch (\Exception $e) {
        $checks['tenants'] = 'fail';
        $healthy = false;
    }

    $status = $healthy ? 'ok' : 'degraded';
    if (! empty($warnings)) {
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
    Route::post('/signup', [TenantController::class, 'signup'])->middleware('throttle:5,1');

    // Central login (non-tenant) — Pillar 2 Part 2 §2.3:
    // add dedicated brute-force throttle (5/min per IP) since the
    // global 120/min IP rate limit (PlatformRateLimitMiddleware) is
    // not credential-guessing-appropriate.
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');

    // Own-profile management — see ProfileController. All endpoints
    // here are scoped to $request->user(); no admin path.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
        Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
        Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar']);
    });

    // Health check
    Route::get('/health', fn () => response()->json(['status' => 'ok']));
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
    Route::post('/signup', [TenantController::class, 'signup'])->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::get('/health', fn () => response()->json(['status' => 'ok']));

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
        Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
        Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar']);
    });
});

/*
|--------------------------------------------------------------------------
| Tenant Accounting Compliance Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/accounting')->middleware([
    'auth:sanctum',
    \App\Http\Middleware\SetTenantFromUserPreference::class,
    'ensure.tenant.search_path',
    \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
])->group(function () {

    Route::prefix('compliance')->group(function () {
        // Reads (issue #100: this whole block previously had no RequirePermission).
        Route::middleware(\App\Http\Middleware\RequirePermission::class.':accounting.view')->group(function () {
            Route::get('/documents', [\App\Http\Controllers\Accounting\ComplianceController::class, 'listDocuments']);
            Route::get('/documents/{id}', [\App\Http\Controllers\Accounting\ComplianceController::class, 'getDocument']);
            Route::get('/documents/{id}/submissions', [\App\Http\Controllers\Accounting\ComplianceController::class, 'getDocumentSubmissions']);
            Route::get('/submissions/{id}', [\App\Http\Controllers\Accounting\ComplianceController::class, 'getSubmission']);
            Route::get('/submissions/{id}/events', [\App\Http\Controllers\Accounting\ComplianceController::class, 'getSubmissionEvents']);
            Route::get('/countries', [\App\Http\Controllers\Accounting\ComplianceController::class, 'listCountries']);
            Route::get('/config/{country_code}', [\App\Http\Controllers\Accounting\ComplianceController::class, 'getConfig']);
            Route::get('/reports/{country_code}/{report_type}', [\App\Http\Controllers\Accounting\ComplianceController::class, 'exportReport']);
            Route::get('/reports/{country_code}/{report_type}/download', [\App\Http\Controllers\Accounting\ComplianceController::class, 'downloadReport']);
        });

        // Submission lifecycle mutations — posting-privilege operations.
        Route::middleware(\App\Http\Middleware\RequirePermission::class.':accounting.post')->group(function () {
            Route::post('/documents/{id}/sign', [\App\Http\Controllers\Accounting\ComplianceController::class, 'signDocument']);
            Route::post('/documents/{id}/submit', [\App\Http\Controllers\Accounting\ComplianceController::class, 'submitDocument']);
            Route::post('/submissions/{id}/retry', [\App\Http\Controllers\Accounting\ComplianceController::class, 'retrySubmission']);
            Route::post('/submissions/{id}/cancel', [\App\Http\Controllers\Accounting\ComplianceController::class, 'cancelSubmission']);
            Route::post('/submissions/{id}/rejection-response', [\App\Http\Controllers\Accounting\ComplianceController::class, 'respondToRejectionRequest']);
            Route::post('/submissions/{id}/sync', [\App\Http\Controllers\Accounting\ComplianceController::class, 'syncSubmission']);

            // Direct submission by source document type
            Route::post('/credit-notes/{creditNoteId}/submit', [\App\Http\Controllers\Accounting\ComplianceController::class, 'submitCreditNote']);
            Route::post('/bills/{billId}/submit', [\App\Http\Controllers\Accounting\ComplianceController::class, 'submitBill']);
            Route::post('/vendor-debit-notes/{debitNoteId}/submit', [\App\Http\Controllers\Accounting\ComplianceController::class, 'submitVendorDebitNote']);
            Route::post('/journal-entries/{journalEntryId}/submit', [\App\Http\Controllers\Accounting\ComplianceController::class, 'submitJournalEntry']);

            // Bulk operations
            Route::post('/sync-pending', [\App\Http\Controllers\Accounting\ComplianceController::class, 'syncPending']);
        });

        // Configuration — higher-privilege mutation.
        Route::put('/config/{country_code}', [\App\Http\Controllers\Accounting\ComplianceController::class, 'updateConfig'])
            ->middleware(\App\Http\Middleware\RequirePermission::class.':accounting.settings');
    });

    // Country Plugin Settings (outside compliance prefix — frontend calls /accounting/tenant/country-plugin-settings)
    Route::get('/tenant/country-plugin-settings', [\App\Http\Controllers\Accounting\CountryPluginSettingsController::class, 'show'])
        ->middleware(\App\Http\Middleware\RequirePermission::class.':accounting.view');
    Route::put('/tenant/country-plugin-settings', [\App\Http\Controllers\Accounting\CountryPluginSettingsController::class, 'update'])
        ->middleware(\App\Http\Middleware\RequirePermission::class.':accounting.settings');
});
