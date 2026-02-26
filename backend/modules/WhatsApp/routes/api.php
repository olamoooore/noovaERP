<?php

use Illuminate\Support\Facades\Route;
use Modules\WhatsApp\Controllers\WhatsAppApiKeyController;
use Modules\WhatsApp\Controllers\WhatsAppWebhookController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Public Webhook Routes (Self-authenticated via header)
Route::prefix('whatsapp/webhooks')->group(function () {
    Route::get('/health', [WhatsAppWebhookController::class, 'health']);
    Route::post('/order-created', [WhatsAppWebhookController::class, 'orderCreated']);
    Route::post('/payment-confirmed', [WhatsAppWebhookController::class, 'paymentConfirmed']);
    Route::post('/order-shipped', [WhatsAppWebhookController::class, 'orderShipped']);
    Route::post('/order-delivered', [WhatsAppWebhookController::class, 'orderDelivered']);
    Route::post('/order-cancelled', [WhatsAppWebhookController::class, 'orderCancelled']);
    Route::post('/send-custom-message', [WhatsAppWebhookController::class, 'sendCustomMessage']);
});

// Protected Management Routes
Route::middleware(['auth:sanctum'])->prefix('whatsapp')->group(function () {
    // API Key Management
    Route::get('/api-keys', [WhatsAppApiKeyController::class, 'index']);
    Route::post('/api-keys', [WhatsAppApiKeyController::class, 'store']);
    Route::put('/api-keys/{id}', [WhatsAppApiKeyController::class, 'update']);
    Route::delete('/api-keys/{id}', [WhatsAppApiKeyController::class, 'destroy']);
    Route::post('/api-keys/{id}/revoke', [WhatsAppApiKeyController::class, 'revoke']);
    Route::post('/api-keys/{id}/activate', [WhatsAppApiKeyController::class, 'activate']);
    Route::post('/api-keys/{id}/test', [WhatsAppApiKeyController::class, 'test']);
});
