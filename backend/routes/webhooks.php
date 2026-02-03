<?php

use Illuminate\Support\Facades\Route;
use Modules\PaymentGateway\Controllers\PaymentWebhookController;

/*
|--------------------------------------------------------------------------
| Webhook Routes (Central - No Tenancy)
|--------------------------------------------------------------------------
| These routes are NOT tenant-scoped because webhooks come from external
| payment gateways and need to work across all domains (ngrok, production, etc.)
| They identify the tenant based on the transaction ID in the webhook payload
*/

Route::prefix('api/v1')->group(function () {
    // Public webhook endpoints - no authentication, no tenancy middleware
    Route::post('/webhooks/stripe', [PaymentWebhookController::class, 'stripe'])->name('webhooks.stripe');
    Route::post('/webhooks/hitpay', [PaymentWebhookController::class, 'hitpay'])->name('webhooks.hitpay');
    Route::post('/webhooks/paypal', [PaymentWebhookController::class, 'paypal'])->name('webhooks.paypal');
});
