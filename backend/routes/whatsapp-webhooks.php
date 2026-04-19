<?php

use App\Http\Middleware\ResolveTenantFromWhatsAppWebhook;
use Illuminate\Support\Facades\Route;
use Modules\WhatsApp\Controllers\WhatsAppWebhookController;

Route::prefix('api/v1/whatsapp/webhooks')->group(function () {
    Route::get('/health', [WhatsAppWebhookController::class, 'health']);

    Route::middleware([ResolveTenantFromWhatsAppWebhook::class])->group(function () {
        Route::post('/order-created', [WhatsAppWebhookController::class, 'orderCreated']);
        Route::post('/payment-confirmed', [WhatsAppWebhookController::class, 'paymentConfirmed']);
        Route::post('/order-shipped', [WhatsAppWebhookController::class, 'orderShipped']);
        Route::post('/order-delivered', [WhatsAppWebhookController::class, 'orderDelivered']);
        Route::post('/order-cancelled', [WhatsAppWebhookController::class, 'orderCancelled']);
        Route::post('/send-custom-message', [WhatsAppWebhookController::class, 'sendCustomMessage']);
    });
});
