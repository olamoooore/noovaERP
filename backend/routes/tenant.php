<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant API Routes
|--------------------------------------------------------------------------
| All routes here are automatically scoped to current tenant
| Schema isolation ensures data security
*/

// -------------------------------------------------------------------------
// PUBLIC API routes — no authentication required
// Tenant resolved from Host header / domain, with fallback to first tenant
// Used by published websites, storefront, and e-commerce scripts
// -------------------------------------------------------------------------
Route::middleware([
    'api',
    \App\Http\Middleware\EnsurePublicSchemaForAuth::class,
    \App\Http\Middleware\InitializeTenantForPublicAccess::class,
])->prefix('api/v1')->group(function () {
    // Website public routes
    Route::prefix('website-public')->group(function () {
        Route::get('/sites/{websiteSlug}', [\Modules\WebsiteBuilder\Http\Controllers\PublicSiteController::class, 'getPage']);
        Route::get('/sites/{websiteSlug}/pages/{pageSlug}', [\Modules\WebsiteBuilder\Http\Controllers\PublicSiteController::class, 'getPage']);
        Route::get('/products', [\Modules\WebsiteBuilder\Http\Controllers\WebsiteProductController::class, 'index']);
        Route::get('/products/{id}', [\Modules\WebsiteBuilder\Http\Controllers\WebsiteProductController::class, 'show']);
        Route::get('/categories', [\Modules\WebsiteBuilder\Http\Controllers\WebsiteProductController::class, 'categories']);
        Route::get('/tags', [\Modules\WebsiteBuilder\Http\Controllers\WebsiteProductController::class, 'tags']);
    });

    // Guest checkout (no login required for buying)
    Route::post('/web-shop/checkout/guest', [\Modules\Ecommerce\Http\Controllers\WebCheckoutController::class, 'guestCheckout']);
});

// -------------------------------------------------------------------------
// AUTHENTICATED API routes — requires auth:sanctum
// Tenant resolved from authenticated user's default company
// -------------------------------------------------------------------------
Route::middleware([
    'api',
    'auth:sanctum',
    \App\Http\Middleware\SetTenantFromUserPreference::class,
    PreventAccessFromCentralDomains::class,
    \App\Http\Middleware\EnsurePublicSchemaForAuth::class,
])->prefix('api/v1')->group(function () {
    $modules = app(\App\Modules\ModuleManager::class);

    foreach ($modules->tenantApiRouteFiles() as $routesFile) {
        require $routesFile;
    }
});
