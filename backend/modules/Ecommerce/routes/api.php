<?php

use Illuminate\Support\Facades\Route;
use Modules\Ecommerce\Http\Controllers\WebCheckoutController;
use Modules\Ecommerce\Http\Controllers\WebOrderController;
use Modules\Ecommerce\Http\Controllers\WebCartController;
use Modules\Ecommerce\Http\Controllers\WebAuthController;
use Modules\Ecommerce\Http\Controllers\WebCustomerController;
use Modules\Ecommerce\Http\Middleware\WebCustomerAuth;

/*
|--------------------------------------------------------------------------
| Ecommerce API Routes
|--------------------------------------------------------------------------
|
| These routes handle the web shop functionality.
|
*/

// Public routes - no authentication required
Route::prefix('web-shop')->group(function () {
    // Auth
    Route::post('/auth/register', [WebAuthController::class, 'register']);
    Route::post('/auth/login', [WebAuthController::class, 'login']);
    
    // Checkout
    Route::post('/checkout/guest', [WebCheckoutController::class, 'guestCheckout']);
    
    // Orders (guest access by token)
    Route::get('/orders/guest/{token}', [WebOrderController::class, 'showGuest']);
});

// Protected routes - customer authentication required
Route::middleware(WebCustomerAuth::class)->prefix('web-shop')->group(function () {
    // Auth
    Route::post('/auth/logout', [WebAuthController::class, 'logout']);
    Route::get('/customer/profile', [WebAuthController::class, 'profile']);
    Route::put('/customer/profile', [WebAuthController::class, 'updateProfile']);
    
    // Customer & Addresses
    Route::get('/customer', [WebCustomerController::class, 'show']);
    Route::get('/customer/addresses', [WebCustomerController::class, 'addresses']);
    Route::post('/customer/addresses', [WebCustomerController::class, 'addAddress']);
    Route::put('/customer/addresses/{id}', [WebCustomerController::class, 'updateAddress']);
    Route::delete('/customer/addresses/{id}', [WebCustomerController::class, 'deleteAddress']);
    Route::post('/customer/addresses/{id}/default', [WebCustomerController::class, 'setDefaultAddress']);
    
    // Orders
    Route::get('/orders', [WebOrderController::class, 'index']);
    Route::get('/orders/{id}', [WebOrderController::class, 'show']);
    
    // Cart (Phase 4)
    // Route::get('/cart', [WebCartController::class, 'get']);
    // Route::post('/cart/sync', [WebCartController::class, 'sync']);
});

// Admin routes
Route::middleware(['auth:sanctum'])->prefix('admin/ecommerce')->group(function () {
    Route::get('/orders', [WebOrderController::class, 'adminIndex']);
    Route::get('/orders/{id}', [WebOrderController::class, 'adminShow']);
    Route::put('/orders/{id}/status', [WebOrderController::class, 'updateStatus']);
});