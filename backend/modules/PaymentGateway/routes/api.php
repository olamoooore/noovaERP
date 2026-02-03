<?php

use Illuminate\Support\Facades\Route;
use Modules\PaymentGateway\Controllers\PaymentGatewayController;
use Modules\PaymentGateway\Controllers\PaymentController;
use Modules\PaymentGateway\Controllers\PaymentWebhookController;
use Modules\PaymentGateway\Controllers\PaymentAnalyticsController;

// Protected routes - require authentication
Route::middleware('auth:sanctum')->group(function () {

    // Payment Gateway Management
    Route::prefix('payment-gateways')->group(function () {
        Route::get('/', [PaymentGatewayController::class, 'index']);
        Route::get('/available', [PaymentGatewayController::class, 'available']);
        Route::post('/', [PaymentGatewayController::class, 'store']);
        Route::get('/{gateway}', [PaymentGatewayController::class, 'show']);
        Route::put('/{gateway}', [PaymentGatewayController::class, 'update']);
        Route::delete('/{gateway}', [PaymentGatewayController::class, 'destroy']);
        Route::post('/{gateway}/test', [PaymentGatewayController::class, 'test']);
        Route::post('/{gateway}/toggle', [PaymentGatewayController::class, 'toggle']);
    });

    // Payment Processing
    Route::prefix('payment-transactions')->group(function () {
        Route::get('/', [PaymentController::class, 'index']);
        Route::post('/create', [PaymentController::class, 'create']);
        Route::post('/confirm', [PaymentController::class, 'confirm']);
        Route::get('/transaction/{reference}', [PaymentController::class, 'getByReference']);
        Route::get('/{transaction}/status', [PaymentController::class, 'status']);
        Route::get('/{transaction}', [PaymentController::class, 'status']); // Alias for status
        Route::post('/{transaction}/refund', [PaymentController::class, 'refund']);
    });

    // Payment Analytics
    Route::get('/payment-analytics', [PaymentAnalyticsController::class, 'index']);
});

// Note: Webhook routes are in backend/routes/webhooks.php (central, no tenancy)
