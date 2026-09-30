<?php

use App\Http\Controllers\Api\EsignWebhookController;
use App\Http\Middleware\InboundEmailAuth;
use App\Http\Middleware\ResolveTenantFromWebhook;
use App\Http\Middleware\SecureWebhookAccess;
use Illuminate\Support\Facades\Route;
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

    // P-04c: customer-facing gateway redirect landings for invoice payment
    // links. Deliberately DB-free: settlement is webhook-driven canonically
    // (never trust a browser arriving at a success URL); these endpoints
    // just exist so the hosted checkout has a REAL place to land (previously
    // hardcoded to routes that never existed) and echo the gateway reference.
    Route::get('/payments/invoice/{invoice}/success', function (\Illuminate\Http\Request $request, int $invoice) {
        return response()->json([
            'status' => 'awaiting_settlement',
            'message' => 'Payment confirmation received. The invoice updates once the gateway notification is processed — you can safely close this page.',
            'invoice_id' => $invoice,
            'reference' => $request->query('ref') ?? $request->query('payment_request_id'),
        ]);
    })->whereNumber('invoice')->name('payments.invoice.success')->middleware('throttle:60,1');

    Route::get('/payments/invoice/{invoice}/cancelled', function (int $invoice) {
        return response()->json([
            'status' => 'cancelled',
            'message' => 'Payment was cancelled. The invoice remains open; a new payment link can be requested from the merchant.',
            'invoice_id' => $invoice,
        ]);
    })->whereNumber('invoice')->name('payments.invoice.cancelled')->middleware('throttle:60,1');

    // PayPal webhooks - secured by rate limiting, replay prevention, certificate verification
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

    // Issue #85: incoming email bridge (IMAP integration).
    // The endpoint accepts a parsed RFC-822 payload from any external
    // source (a custom IMAP poller, SendGrid Inbound Parse, Microsoft
    // Graph subscription, or the included `inbound-email:poll` artisan
    // command). Authenticated via a shared secret in the
    // `X-Inbound-Email-Token` header so an unauthenticated public network
    // cannot flood the chatter with spam.
    Route::post('/webhooks/inbound-email', [App\Http\Controllers\Api\InboundEmailController::class, 'ingest'])
        ->name('webhooks.inbound_email')
        ->middleware([
            InboundEmailAuth::class,
        ]);
});
