<?php

use Illuminate\Support\Facades\Route;
use Modules\Fulfillment\Http\Controllers\FulfillmentController;
use Modules\Fulfillment\Http\Controllers\ProviderController;
use Modules\Fulfillment\Http\Controllers\AdminFulfillmentController;

// Public routes - for storefront checkout
Route::prefix('fulfillment')->middleware(['api', 'tenant'])->group(function () {
    Route::get('/providers', [ProviderController::class, 'index']);
    Route::post('/providers', [ProviderController::class, 'store']);
    Route::get('/providers/available-carriers', [ProviderController::class, 'getAvailableCarriers']);
    Route::get('/providers/{id}', [ProviderController::class, 'show']);
    Route::put('/providers/{id}', [ProviderController::class, 'update']);
    Route::delete('/providers/{id}', [ProviderController::class, 'destroy']);
    Route::post('/providers/{id}/test', [ProviderController::class, 'testCredentials']);

    Route::post('/rates', [FulfillmentController::class, 'calculateRates']);

    Route::get('/shipments', [FulfillmentController::class, 'listShipments']);
    Route::post('/shipments', [FulfillmentController::class, 'createShipment']);
    Route::get('/shipments/{id}', [FulfillmentController::class, 'getShipment']);
    Route::post('/shipments/{id}/cancel', [FulfillmentController::class, 'cancelShipment']);
    Route::get('/shipments/{id}/tracking', [FulfillmentController::class, 'getTracking']);
    Route::post('/shipments/{id}/sync-tracking', [FulfillmentController::class, 'syncTracking']);
});

// Admin routes - require authentication
Route::middleware(['auth:sanctum'])->group(function () {
    Route::prefix('admin/fulfillment')->group(function () {
        Route::get('/providers', [AdminFulfillmentController::class, 'providers']);
        Route::post('/providers', [AdminFulfillmentController::class, 'createProvider']);
        Route::get('/providers/available', [AdminFulfillmentController::class, 'availableCarriers']);
        Route::get('/providers/{id}', [AdminFulfillmentController::class, 'showProvider']);
        Route::put('/providers/{id}', [AdminFulfillmentController::class, 'updateProvider']);
        Route::delete('/providers/{id}', [AdminFulfillmentController::class, 'deleteProvider']);
        Route::post('/providers/{id}/test', [AdminFulfillmentController::class, 'testCredentials']);
        Route::post('/providers/{id}/toggle', [AdminFulfillmentController::class, 'toggleProvider']);

        Route::get('/shipments', [AdminFulfillmentController::class, 'shipments']);
        Route::get('/shipments/{id}', [AdminFulfillmentController::class, 'showShipment']);
        Route::post('/shipments/{id}/create-label', [AdminFulfillmentController::class, 'createLabel']);
    });
});