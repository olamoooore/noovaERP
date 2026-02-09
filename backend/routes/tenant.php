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

    // Payment confirmation (called after Stripe success)
    Route::post('/web-shop/checkout/confirm-payment', [\Modules\Ecommerce\Http\Controllers\WebCheckoutController::class, 'confirmPayment']);

    // Available payment gateways
    Route::get('/web-shop/payment-gateways', [\Modules\Ecommerce\Http\Controllers\WebCheckoutController::class, 'getPaymentGateways']);

    // Guest order lookup by token
    Route::get('/web-shop/orders/guest/{token}', [\Modules\Ecommerce\Http\Controllers\WebOrderController::class, 'showGuest']);

    // Web shop auth (public - no login required)
    Route::post('/web-shop/auth/register', [\Modules\Ecommerce\Http\Controllers\WebAuthController::class, 'register']);
    Route::post('/web-shop/auth/login', [\Modules\Ecommerce\Http\Controllers\WebAuthController::class, 'login']);

    // Web shop customer-authenticated routes (uses WebCustomerAuth, NOT auth:sanctum)
    Route::middleware([\Modules\Ecommerce\Http\Middleware\WebCustomerAuth::class])->prefix('web-shop')->group(function () {
        Route::post('/auth/logout', [\Modules\Ecommerce\Http\Controllers\WebAuthController::class, 'logout']);
        Route::get('/customer/profile', [\Modules\Ecommerce\Http\Controllers\WebAuthController::class, 'profile']);
        Route::put('/customer/profile', [\Modules\Ecommerce\Http\Controllers\WebAuthController::class, 'updateProfile']);
        Route::get('/customer', [\Modules\Ecommerce\Http\Controllers\WebCustomerController::class, 'show']);
        Route::get('/customer/addresses', [\Modules\Ecommerce\Http\Controllers\WebCustomerController::class, 'addresses']);
        Route::post('/customer/addresses', [\Modules\Ecommerce\Http\Controllers\WebCustomerController::class, 'addAddress']);
        Route::put('/customer/addresses/{id}', [\Modules\Ecommerce\Http\Controllers\WebCustomerController::class, 'updateAddress']);
        Route::delete('/customer/addresses/{id}', [\Modules\Ecommerce\Http\Controllers\WebCustomerController::class, 'deleteAddress']);
        Route::post('/customer/addresses/{id}/default', [\Modules\Ecommerce\Http\Controllers\WebCustomerController::class, 'setDefaultAddress']);
        Route::get('/orders', [\Modules\Ecommerce\Http\Controllers\WebOrderController::class, 'index']);
        Route::get('/orders/{id}', [\Modules\Ecommerce\Http\Controllers\WebOrderController::class, 'show']);
    });
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
