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
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
])->prefix('api/v1')->group(function () {
    // Website public routes
    Route::prefix('website-public')->group(function () {
        Route::get('/sites/{websiteSlug}', [\Modules\WebsiteBuilder\Http\Controllers\PublicSiteController::class, 'getPage']);
        Route::get('/sites/{websiteSlug}/pages/{pageSlug}', [\Modules\WebsiteBuilder\Http\Controllers\PublicSiteController::class, 'getPage']);
        Route::get('/products', [\Modules\WebsiteBuilder\Http\Controllers\WebsiteProductController::class, 'index']);
        Route::get('/products/{id}', [\Modules\WebsiteBuilder\Http\Controllers\WebsiteProductController::class, 'show']);
        Route::get('/categories', [\Modules\WebsiteBuilder\Http\Controllers\WebsiteProductController::class, 'categories']);
        Route::get('/tags', [\Modules\WebsiteBuilder\Http\Controllers\WebsiteProductController::class, 'tags']);

        // Public event routes — no auth required (storefront /store/events)
        Route::get('/events', [\Modules\EventManagement\Http\Controllers\PublicEventController::class, 'index']);
        Route::get('/events/{id}', [\Modules\EventManagement\Http\Controllers\PublicEventController::class, 'show']);
        Route::post('/events/{id}/prepare-checkout', [\Modules\EventManagement\Http\Controllers\PublicEventController::class, 'prepareCheckout']);
        Route::post('/events/{id}/book', [\Modules\EventManagement\Http\Controllers\PublicEventController::class, 'book']);
        Route::post('/events/bookings/confirm-payment', [\Modules\EventManagement\Http\Controllers\PublicEventController::class, 'confirmPayment']);
    });

    // Guest checkout (no login required for buying)
    Route::post('/web-shop/checkout/guest', [\Modules\Ecommerce\Http\Controllers\WebCheckoutController::class, 'guestCheckout']);

    // Payment confirmation (called after Stripe success)
    Route::post('/web-shop/checkout/confirm-payment', [\Modules\Ecommerce\Http\Controllers\WebCheckoutController::class, 'confirmPayment']);

    // Shipping rates for checkout
    Route::get('/web-shop/shipping-rates', [\Modules\Ecommerce\Http\Controllers\ShippingRatesController::class, 'getRates']);

    // Available payment gateways
    Route::get('/web-shop/payment-gateways', [\Modules\Ecommerce\Http\Controllers\WebCheckoutController::class, 'getPaymentGateways']);

    // Web shop queue admission (waiting room)
    Route::post('/web-shop/queue/join', [\Modules\Ecommerce\Http\Controllers\CheckoutQueueController::class, 'join']);
    Route::post('/web-shop/queue/status', [\Modules\Ecommerce\Http\Controllers\CheckoutQueueController::class, 'status']);
    Route::post('/web-shop/queue/heartbeat', [\Modules\Ecommerce\Http\Controllers\CheckoutQueueController::class, 'heartbeat']);
    Route::post('/web-shop/queue/leave', [\Modules\Ecommerce\Http\Controllers\CheckoutQueueController::class, 'leave']);

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
        Route::post('/orders/{id}/cancel', [\Modules\Ecommerce\Http\Controllers\WebOrderController::class, 'cancelOrder']);
        Route::post('/orders/{id}/retry-payment', [\Modules\Ecommerce\Http\Controllers\WebOrderController::class, 'retryPayment']);
        Route::get('/orders/{id}/invoice', [\Modules\Ecommerce\Http\Controllers\WebOrderController::class, 'getInvoice']);
        Route::get('/tickets', [\Modules\EventManagement\Http\Controllers\WebCustomerTicketController::class, 'index']);
        
        // Loyalty routes for web shop customers
        Route::get('/loyalty/balance', [\Modules\Loyalty\Http\Controllers\WebLoyaltyController::class, 'balance']);
        Route::get('/loyalty/history', [\Modules\Loyalty\Http\Controllers\WebLoyaltyController::class, 'history']);
        Route::post('/loyalty/calculate', [\Modules\Loyalty\Http\Controllers\WebLoyaltyController::class, 'calculate']);
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
    \App\Http\Middleware\EnsureTenantSearchPath::class,
    \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
])->prefix('api/v1')->group(function () {
    // Document correction endpoints
    Route::prefix('corrections')->middleware(\App\Http\Middleware\RequirePermission::class . ':accounting.post')->group(function () {
        // Invoice corrections
        Route::post('/invoices/{id}/void', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'voidInvoice']);
        Route::post('/invoices/{id}/reverse', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'reverseInvoice']);
        Route::post('/invoices/{id}/credit-note', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'createInvoiceCreditNote']);
        Route::post('/invoices/{id}/refund', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'refundInvoice']);
        Route::post('/invoices/{id}/replacement', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'createInvoiceReplacement']);

        // Bill corrections
        Route::post('/bills/{id}/void', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'voidBill']);
        Route::post('/bills/{id}/reverse', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'reverseBill']);
        Route::post('/bills/{id}/credit-note', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'createBillCreditNote']);
        Route::post('/bills/{id}/replacement', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'createBillReplacement']);
    });

    // Journal corrections require accounting.reverse
    Route::prefix('corrections')->middleware(\App\Http\Middleware\RequirePermission::class . ':accounting.reverse')->group(function () {
        Route::post('/journals/{id}/reverse', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'reverseJournal']);
        Route::post('/journals/{id}/replacement', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'createJournalReplacement']);
    });

    // Payment corrections require accounting.post
    Route::prefix('corrections')->middleware(\App\Http\Middleware\RequirePermission::class . ':accounting.post')->group(function () {
        Route::post('/payments/{id}/refund', [\App\Http\Controllers\Api\PaymentCorrectionsController::class, 'refundPayment']);
        Route::post('/payments/{id}/reverse', [\App\Http\Controllers\Api\PaymentCorrectionsController::class, 'reversePayment']);
        Route::post('/payments/{id}/unapply', [\App\Http\Controllers\Api\PaymentCorrectionsController::class, 'unapplyPayment']);

        // Bill payment corrections
        Route::post('/bill-payments/{id}/refund', [\App\Http\Controllers\Api\PaymentCorrectionsController::class, 'refundBillPayment']);
        Route::post('/bill-payments/{id}/reverse', [\App\Http\Controllers\Api\PaymentCorrectionsController::class, 'reverseBillPayment']);
        Route::post('/bill-payments/{id}/unapply', [\App\Http\Controllers\Api\PaymentCorrectionsController::class, 'unapplyBillPayment']);
    });

    // Tenant user management
    Route::prefix('tenant-users')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\TenantUserController::class, 'index']);
        Route::put('/{id}/role', [\App\Http\Controllers\Api\TenantUserController::class, 'updateRole'])->middleware(\App\Http\Middleware\RequirePermission::class . ':users.write');
        Route::delete('/{id}', [\App\Http\Controllers\Api\TenantUserController::class, 'destroy'])->middleware(\App\Http\Middleware\RequirePermission::class . ':users.delete');
    });

    // Version/correction chain endpoints
    Route::get('/invoices/{id}/correction-chain', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'getInvoiceCorrectionChain']);
    Route::get('/bills/{id}/correction-chain', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'getBillCorrectionChain']);
    Route::get('/journals/{id}/correction-chain', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'getJournalCorrectionChain']);

    $modules = app(\App\Modules\ModuleManager::class);

    foreach ($modules->tenantApiRouteFiles() as $routesFile) {
        require $routesFile;
    }

    // ─── Approval Workflow ────────────────────────────────────────────────────
    // Tenant-scoped approval requests for POS void/refund/return/discount.
    // Requires auth:sanctum (inherited from outer middleware group).
    Route::prefix('approvals')->group(function () {
        Route::post('/',                [\App\Http\Controllers\ApprovalController::class, 'store']);
        Route::get('/',                 [\App\Http\Controllers\ApprovalController::class, 'index']);
        Route::get('/my-requests',      [\App\Http\Controllers\ApprovalController::class, 'myRequests']);
        Route::get('/{id}',             [\App\Http\Controllers\ApprovalController::class, 'show']);
        Route::post('/{id}/approve',    [\App\Http\Controllers\ApprovalController::class, 'approve']);
        Route::post('/{id}/reject',     [\App\Http\Controllers\ApprovalController::class, 'reject']);
    });
});
