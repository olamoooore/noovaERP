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
    Route::get('/w/{websiteSlug}/{pageSlug}', [\Modules\WebsiteBuilder\Http\Controllers\PublicWebsiteRenderController::class, 'render'])
        ->where('pageSlug', '.*');

    // SEO crawl surface (NFR-08/FR-SF-12): per-tenant sitemap + robots,
    // resolved from the SAME host→tenant middleware as /w/ pages — a
    // sitemap can only ever list the resolving tenant's own URLs.
    Route::get('/sitemap.xml', [\Modules\Storefront\Http\Controllers\Public\PublicSeoController::class, 'sitemap']);
    Route::get('/robots.txt', [\Modules\Storefront\Http\Controllers\Public\PublicSeoController::class, 'robots']);

    // Redirect /sites/ URLs to /w/ so existing links in page content don't break
    Route::get('/sites/{websiteSlug}', function (string $websiteSlug) {
        return redirect("/w/{$websiteSlug}", 301);
    });
    Route::get('/sites/{websiteSlug}/{pageSlug}', function (string $websiteSlug, string $pageSlug) {
        return redirect("/w/{$websiteSlug}/{$pageSlug}", 301);
    });
});

// Serve logo and other assets that may be referenced as relative paths in page content
// e.g. /noovaerplogo.png → /storage/noovaerplogo.png
Route::get('/{filename}', function (string $filename) {
    $path = storage_path("app/public/{$filename}");
    if (file_exists($path) && in_array(pathinfo($filename, PATHINFO_EXTENSION), ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico'])) {
        return response()->file($path, [
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
    abort(404);
})->where('filename', '^[a-zA-Z0-9_\-]+\.(png|jpe?g|gif|svg|webp|ico)$');
