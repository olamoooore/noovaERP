<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['message' => 'NoovaERP API is running'];
});

// Some middleware/exception paths redirect unauthenticated (non-JSON) requests to
// a named 'login' route. This app is SPA/API-first, so we provide a lightweight
// endpoint to avoid 500s.
Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');

// -------------------------------------------------------------------------
// Published Website Routes (public, persistent URLs like Odoo)
// These serve complete HTML documents for published websites.
// Accessible at /w/{websiteSlug} and /w/{websiteSlug}/{pageSlug}
// -------------------------------------------------------------------------
Route::middleware([
    \App\Http\Middleware\EnsurePublicSchemaForAuth::class,
    \App\Http\Middleware\InitializeTenantForPublicAccess::class,
])->group(function () {
    Route::get('/w/{websiteSlug}', [\Modules\WebsiteBuilder\Http\Controllers\PublicWebsiteRenderController::class, 'render']);
    Route::get('/w/{websiteSlug}/{pageSlug}', [\Modules\WebsiteBuilder\Http\Controllers\PublicWebsiteRenderController::class, 'render']);
});
