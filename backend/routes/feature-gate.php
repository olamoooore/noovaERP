<?php

declare(strict_types=1);

use App\Http\Controllers\FeatureGateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Feature Gate API Routes — /api/v1/features
|--------------------------------------------------------------------------
|
| Called by tenant ERP modules at runtime to check which features/modules
| are enabled for the authenticated tenant.
|
| Auth: Authorization: Bearer ten_{40-char-random}
|
| All responses include a 'cached_at' timestamp.
| Pass header X-Feature-Gate-Fresh: 1 to bypass the cache.
|
*/

// GET /api/v1/features — all entitled features
Route::get('/', [FeatureGateController::class, 'all']);

// POST /api/v1/features/check-many — batch check (MUST be before /{key})
Route::post('/check-many', [FeatureGateController::class, 'checkMany']);

// GET /api/v1/features/{key} — single feature check
Route::get('/{key}', [FeatureGateController::class, 'check'])
    ->where('key', '[a-z][a-z0-9_.]*');
