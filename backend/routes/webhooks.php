<?php

use App\Http\Middleware\ResolveTenantFromWebhook;
use App\Http\Middleware\SecureWebhookAccess;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\EsignWebhookController;
use Modules\PaymentGateway\Controllers\PaymentWebhookController;

/*
|--------------------------------------------------------------------------
| Secure Webhook Routes (Central with Security)
|--------------------------------------------------------------------------
| Workstream 2 (2026-04-15): These routes are secured with multi-layer protection:
|
|   1. SecureWebhookAccess - Rate limiting, IP checks, replay prevention
|   2. ResolveTenantFromWebhook - Efficient tenant lookup (no enumeration)
|   3. Signature verification - Each gateway verifies its own signatures
|
| The tenant is resolved from the shared central webhook lookup table
| using the canonical transaction ID from the webhook payload, NOT by
| scanning all tenants (which was a critical security vulnerability).
*/

Route::prefix('api/v1')->group(function () {
    // Stripe webhooks - secured with rate limiting, replay prevention, and signature verification
    Route::post('/webhooks/stripe', [PaymentWebhookController::class, 'stripe'])
        ->name('webhooks.stripe')
        ->middleware([
            SecureWebhookAccess::class.':stripe',
            ResolveTenantFromWebhook::class.':stripe',
        ]);

    // HitPay webhooks - secured with rate limiting, replay prevention, and HMAC verification
    Route::post('/webhooks/hitpay', [PaymentWebhookController::class, 'hitpay'])
        ->name('webhooks.hitpay')
        ->middleware([
            SecureWebhookAccess::class.':hitpay',
            ResolveTenantFromWebhook::class.':hitpay',
        ]);

    // PayPal webhooks - secured with rate limiting, replay prevention, and certificate verification
    Route::post('/webhooks/paypal', [PaymentWebhookController::class, 'paypal'])
        ->name('webhooks.paypal')
        ->middleware([
            SecureWebhookAccess::class.':paypal',
            ResolveTenantFromWebhook::class.':paypal',
        ]);

    // E-sign provider webhooks — signature verified by the provider implementation
    Route::post('/webhooks/esign/{provider}', [EsignWebhookController::class, 'handle'])
        ->name('webhooks.esign')
        ->middleware([
            SecureWebhookAccess::class.':esign',
        ]);
});
