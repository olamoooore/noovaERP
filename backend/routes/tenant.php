<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

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

    // -------------------------------------------------------------------------
    // Storefront public runtime (generic commerce module, MRD P1).
    // Tenant from host (InitializeTenantForPublicAccess, fail-closed), gated by
    // entitlement:storefront so an unentitled tenant 403s here AND its published
    // pages strip functions (A-04). Prices/totals are server-computed only.
    // -------------------------------------------------------------------------
    // IMPORTANT (discovered by SF009): Laravel's anonymous throttle signs the
    // bucket as sha1(domain|ip) WITHOUT the route, so stacked `throttle:`
    // middlewares (and sibling routes) would share ONE counter. Every
    // limiter below therefore carries an explicit PREFIX (3rd arg) to keep
    // abuse on one endpoint from locking out the others (FR-PLAT-05).
    Route::middleware(['entitlement:storefront'])
        ->prefix('storefront')
        ->group(function () {
            Route::get('/catalog/products', [\Modules\Storefront\Http\Controllers\Public\CatalogController::class, 'products'])
                ->middleware('throttle:120,1,sf-catalog');

            // Website AI assistant (guest chat for the shop/website).
            // Config is always readable (the widget hides itself when the
            // assistant is disabled); send/poll are throttled in their own
            // buckets and bound to a client visitor_id for guest isolation.
            Route::get('/assistant', [\Modules\AI\Http\Controllers\StorefrontAssistantController::class, 'config'])
                ->middleware('throttle:60,1,sf-assistant');
            Route::post('/chat', [\Modules\AI\Http\Controllers\StorefrontAssistantController::class, 'send'])
                ->middleware('throttle:20,1,sf-chat');
            Route::get('/chat/{conversationId}/poll', [\Modules\AI\Http\Controllers\StorefrontAssistantController::class, 'poll'])
                ->whereNumber('conversationId')
                ->middleware('throttle:120,1,sf-chat-poll');
            Route::get('/cart', [\Modules\Storefront\Http\Controllers\Public\CartController::class, 'show'])
                ->middleware('throttle:120,1,sf-cart');
            Route::post('/cart/items', [\Modules\Storefront\Http\Controllers\Public\CartController::class, 'storeItem'])
                ->middleware('throttle:60,1,sf-cart');
            Route::put('/cart/items/{item}', [\Modules\Storefront\Http\Controllers\Public\CartController::class, 'updateItem'])
                ->whereNumber('item')
                ->middleware('throttle:60,1,sf-cart');
            Route::patch('/cart/items/{item}', [\Modules\Storefront\Http\Controllers\Public\CartController::class, 'updateItem'])
                ->whereNumber('item')
                ->middleware('throttle:60,1,sf-cart');
            Route::delete('/cart/items/{item}', [\Modules\Storefront\Http\Controllers\Public\CartController::class, 'destroyItem'])
                ->whereNumber('item')
                ->middleware('throttle:60,1,sf-cart');

            // Checkout + confirmation (FR-CO-04..06/10, P2): idempotent order
            // placement against the server-quoted cart; token-gated
            // confirmation page data. Payment intent wiring lands with the
            // gateway completion slice.
            Route::post('/checkout', [\Modules\Storefront\Http\Controllers\Public\CheckoutController::class, 'store'])
                ->middleware('throttle:10,1,sf-checkout');
            Route::get('/orders/confirm/{token}', [\Modules\Storefront\Http\Controllers\Public\CheckoutController::class, 'confirm'])
                ->where('token', '[a-f0-9]{32,64}')
                ->middleware('throttle:20,1,sf-confirm');

            // Payment initiation + reconciliation (FR-PAY-01/02/08). The
            // gateway claim is re-verified server-side before completion.
            Route::get('/payment-methods', [\Modules\Storefront\Http\Controllers\Public\PayController::class, 'methods'])
                ->middleware('throttle:30,1,sf-pay');
            Route::post('/orders/{order}/pay', [\Modules\Storefront\Http\Controllers\Public\PayController::class, 'pay'])
                ->whereNumber('order')
                ->middleware('throttle:10,1,sf-pay');
            Route::post('/orders/{order}/confirm-payment', [\Modules\Storefront\Http\Controllers\Public\PayController::class, 'confirm'])
                ->whereNumber('order')
                ->middleware('throttle:15,1,sf-pay');
            // Offline settlement proof upload (PAY-R13): multipart, same
            // ownership guards as /pay, own throttle bucket.
            Route::post('/orders/{order}/proof-of-payment', [\Modules\Storefront\Http\Controllers\Public\PayController::class, 'proof'])
                ->whereNumber('order')
                ->middleware('throttle:10,1,sf-proof');
            // Customer return request (FR-ORD-06), same ownership model.
            Route::post('/orders/{order}/return', [\Modules\Storefront\Http\Controllers\Public\PayController::class, 'requestReturn'])
                ->whereNumber('order')
                ->middleware('throttle:10,1,sf-return');

            // Account identity (FR-ID-*). Brute-force-specific throttles,
            // each in its OWN bucket (see prefix note above).
            Route::prefix('auth')->group(function () {
                Route::post('/register', [\Modules\Storefront\Http\Controllers\Public\AuthController::class, 'register'])
                    ->middleware('throttle:10,1,sf-register');
                Route::post('/login', [\Modules\Storefront\Http\Controllers\Public\AuthController::class, 'login'])
                    ->middleware('throttle:10,1,sf-login');
                Route::post('/forgot-password', [\Modules\Storefront\Http\Controllers\Public\AuthController::class, 'forgotPassword'])
                    ->middleware('throttle:10,1,sf-forgot');
                Route::post('/reset-password', [\Modules\Storefront\Http\Controllers\Public\AuthController::class, 'resetPassword'])
                    ->middleware('throttle:10,1,sf-reset');
                Route::post('/verify-email', [\Modules\Storefront\Http\Controllers\Public\AuthController::class, 'verifyEmail'])
                    ->middleware('throttle:20,1,sf-verify');
            });

            // Cookie-session protected subset: StorefrontAccountAuth re-checks
            // session + account status EVERY request (FR-ID-08) and enforces
            // the double-submit CSRF header on mutations (FR-ID-09).
            Route::middleware([\Modules\Storefront\Http\Middleware\StorefrontAccountAuth::class, 'throttle:60,1,sf-account'])->prefix('account')->group(function () {
                Route::get('/', [\Modules\Storefront\Http\Controllers\Public\AccountController::class, 'show']);

                // Orders (FR-DASH-02)
                Route::get('/orders', [\Modules\Storefront\Http\Controllers\Public\AccountController::class, 'orders']);
                Route::get('/orders/{order}', [\Modules\Storefront\Http\Controllers\Public\AccountController::class, 'order'])
                    ->whereNumber('order');
                Route::post('/orders/{order}/cancel', [\Modules\Storefront\Http\Controllers\Public\AccountController::class, 'cancelOrder'])
                    ->whereNumber('order');
                Route::post('/orders/{order}/reorder', [\Modules\Storefront\Http\Controllers\Public\AccountController::class, 'reorder'])
                    ->whereNumber('order');

                // Addresses (FR-DASH-03)
                Route::get('/addresses', [\Modules\Storefront\Http\Controllers\Public\AccountController::class, 'addresses']);
                Route::post('/addresses', [\Modules\Storefront\Http\Controllers\Public\AccountController::class, 'storeAddress']);
                Route::put('/addresses/{address}', [\Modules\Storefront\Http\Controllers\Public\AccountController::class, 'updateAddress'])
                    ->whereNumber('address');
                Route::delete('/addresses/{address}', [\Modules\Storefront\Http\Controllers\Public\AccountController::class, 'destroyAddress'])
                    ->whereNumber('address');

                // Profile / security (FR-DASH-04, FR-ID-03/06)
                Route::put('/profile', [\Modules\Storefront\Http\Controllers\Public\AccountController::class, 'updateProfile']);
                Route::post('/password', [\Modules\Storefront\Http\Controllers\Public\AccountController::class, 'changePassword']);
                Route::get('/sessions', [\Modules\Storefront\Http\Controllers\Public\AccountController::class, 'sessions']);
                Route::delete('/sessions/{session}', [\Modules\Storefront\Http\Controllers\Public\AccountController::class, 'revokeSession'])
                    ->whereNumber('session');
            });

            Route::middleware([\Modules\Storefront\Http\Middleware\StorefrontAccountAuth::class, 'throttle:60,1,sf-account'])->group(function () {
                Route::post('/auth/logout', [\Modules\Storefront\Http\Controllers\Public\AuthController::class, 'logout']);
                Route::post('/auth/refresh', [\Modules\Storefront\Http\Controllers\Public\AuthController::class, 'refresh']);
            });
        });

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

    // Invoice public acceptance links (signed, expiring — no auth required)
    Route::get('/invoice-accept/{token}', [\App\Http\Controllers\Api\InvoiceAcceptanceController::class, 'show']);
    Route::post('/invoice-accept/{token}/accept', [\App\Http\Controllers\Api\InvoiceAcceptanceController::class, 'accept']);
    Route::post('/invoice-accept/{token}/evidence', [\App\Http\Controllers\Api\InvoiceAcceptanceController::class, 'uploadEvidence']);

    // Delivery-order customer portal (view DO, upload signed copy / evidence)
    Route::get('/do-accept/{token}', [\Modules\Sales\Http\Controllers\DeliveryOrderAcceptanceController::class, 'show']);
    Route::post('/do-accept/{token}/evidence', [\Modules\Sales\Http\Controllers\DeliveryOrderAcceptanceController::class, 'uploadEvidence']);

    // Built-in e-signature (self-hosted): the customer signs the real document.
    Route::get('/sign/{token}', [\App\Http\Controllers\Api\EsignController::class, 'show']);
    Route::get('/sign/{token}/pdf', [\App\Http\Controllers\Api\EsignController::class, 'pdf']);
    Route::post('/sign/{token}', [\App\Http\Controllers\Api\EsignController::class, 'sign'])
        ->middleware('throttle:10,1');
    Route::post('/sign/{token}/upload', [\App\Http\Controllers\Api\EsignController::class, 'upload'])
        ->middleware('throttle:10,1');

    // Issue #149: online quotation acceptance / eSignature portal.
    Route::get('/quotation-accept/{token}', [\App\Http\Controllers\Api\SalesOrderAcceptanceController::class, 'show']);
    Route::post('/quotation-accept/{token}/accept', [\App\Http\Controllers\Api\SalesOrderAcceptanceController::class, 'accept'])
        ->middleware('throttle:5,1');

    // Web shop auth (public - no login required)
    // Pillar 2 Part 2 §2.3: dedicated brute-force throttle (5/min per IP).
    Route::post('/web-shop/auth/register', [\Modules\Ecommerce\Http\Controllers\WebAuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/web-shop/auth/login', [\Modules\Ecommerce\Http\Controllers\WebAuthController::class, 'login'])->middleware('throttle:5,1');

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
    // Issue #173 (CRITICAL): SuperAdmin impersonation. Runs AFTER auth:sanctum
    // (so the actor is authenticated) but BEFORE SetTenantFromUserPreference —
    // when the X-Impersonation-Token header is present it swaps the authenticated
    // user to the target tenant user and initializes tenancy for that user's
    // tenant, so every downstream tenant middleware runs as the impersonated
    // user. Before this registration the middleware was never wired anywhere,
    // making the whole impersonation feature a silent no-op.
    \App\Http\Middleware\ImpersonationMiddleware::class,
    \App\Http\Middleware\SetTenantFromUserPreference::class,
    \App\Http\Middleware\EnsureTenantSearchPath::class,
    \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
])->prefix('api/v1')->group(function () {
    // Chatter (Odoo-style activity log + messages + activities + attachments)
    Route::prefix('chatter')->group(function () {
        Route::get('/{subjectType}/{subjectId}', [\App\Http\Controllers\Api\ChatterController::class, 'show']);
        // Issue #87: polling endpoint for the real-time chatter UX. Frontend
        // hits this every ~10s while the chatter tab is open.
        Route::get('/{subjectType}/{subjectId}/since', [\App\Http\Controllers\Api\ChatterController::class, 'since']);
        Route::post('/{subjectType}/{subjectId}/messages', [\App\Http\Controllers\Api\ChatterController::class, 'addMessage']);
        Route::post('/{subjectType}/{subjectId}/activities', [\App\Http\Controllers\Api\ChatterController::class, 'addActivity']);
        Route::post('/{subjectType}/{subjectId}/activities/{activityId}/done', [\App\Http\Controllers\Api\ChatterController::class, 'markActivityDone']);
        Route::post('/{subjectType}/{subjectId}/attachments', [\App\Http\Controllers\Api\ChatterController::class, 'uploadAttachment']);
        Route::get('/{subjectType}/{subjectId}/attachments/{attachmentId}/download', [\App\Http\Controllers\Api\ChatterController::class, 'downloadAttachment']);
        Route::delete('/{subjectType}/{subjectId}/attachments/{attachmentId}', [\App\Http\Controllers\Api\ChatterController::class, 'deleteAttachment']);
    });

    // Document correction endpoints
    Route::prefix('corrections')->middleware(\App\Http\Middleware\RequirePermission::class.':accounting.post')->group(function () {
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
    Route::prefix('corrections')->middleware(\App\Http\Middleware\RequirePermission::class.':accounting.reverse')->group(function () {
        Route::post('/journals/{id}/reverse', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'reverseJournal']);
        Route::post('/journals/{id}/replacement', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'createJournalReplacement']);
    });

    // Payment corrections require accounting.post
    Route::prefix('corrections')->middleware(\App\Http\Middleware\RequirePermission::class.':accounting.post')->group(function () {
        Route::post('/payments/{id}/refund', [\App\Http\Controllers\Api\PaymentCorrectionsController::class, 'refundPayment']);
        Route::post('/payments/{id}/reverse', [\App\Http\Controllers\Api\PaymentCorrectionsController::class, 'reversePayment']);
        Route::post('/payments/{id}/unapply', [\App\Http\Controllers\Api\PaymentCorrectionsController::class, 'unapplyPayment']);

        // Bill payment corrections
        Route::post('/bill-payments/{id}/refund', [\App\Http\Controllers\Api\PaymentCorrectionsController::class, 'refundBillPayment']);
        Route::post('/bill-payments/{id}/reverse', [\App\Http\Controllers\Api\PaymentCorrectionsController::class, 'reverseBillPayment']);
        Route::post('/bill-payments/{id}/unapply', [\App\Http\Controllers\Api\PaymentCorrectionsController::class, 'unapplyBillPayment']);
    });

    // File upload
    Route::post('/upload', [\App\Http\Controllers\Api\UploadController::class, 'store']);

    // Tenant user management
    Route::prefix('tenant-users')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\TenantUserController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\TenantUserController::class, 'invite'])->middleware(\App\Http\Middleware\RequirePermission::class.':users.write');
        // User-creation requests — creating users is platform-only; owners/
        // admins request new accounts here and the SuperAdmin console reviews.
        Route::get('/requests', [\App\Http\Controllers\Api\UserRequestController::class, 'index'])->middleware(\App\Http\Middleware\RequirePermission::class.':users.write');
        Route::post('/requests', [\App\Http\Controllers\Api\UserRequestController::class, 'store'])->middleware(\App\Http\Middleware\RequirePermission::class.':users.write');
        Route::put('/{id}/role', [\App\Http\Controllers\Api\TenantUserController::class, 'updateRole'])->middleware(\App\Http\Middleware\RequirePermission::class.':users.write');
        // Owner/Admin-only credential management (enforced in-controller too).
        Route::get('/{id}/activity', [\App\Http\Controllers\Api\TenantUserController::class, 'activity'])->middleware(\App\Http\Middleware\RequirePermission::class.':users.write');
        Route::post('/{id}/password-link', [\App\Http\Controllers\Api\TenantUserController::class, 'sendPasswordLink'])->middleware(\App\Http\Middleware\RequirePermission::class.':users.write');
        Route::post('/{id}/password', [\App\Http\Controllers\Api\TenantUserController::class, 'setPassword'])->middleware(\App\Http\Middleware\RequirePermission::class.':users.write');
        Route::delete('/{id}', [\App\Http\Controllers\Api\TenantUserController::class, 'destroy'])->middleware(\App\Http\Middleware\RequirePermission::class.':users.delete');
    });

    // Tenant domain onboarding (FR-PLAT-03 / A-26): central-schema rows
    // scoped to the caller's tenant; workspace-level configuration, so
    // settings.view / settings.write from the PERMISSION_MATRIX.
    Route::prefix('tenant-domains')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\TenantDomainController::class, 'index'])
            ->middleware(\App\Http\Middleware\RequirePermission::class.':settings.view');
        Route::post('/', [\App\Http\Controllers\Api\TenantDomainController::class, 'store'])
            ->middleware(\App\Http\Middleware\RequirePermission::class.':settings.write');
        Route::delete('/{domainId}', [\App\Http\Controllers\Api\TenantDomainController::class, 'destroy'])
            ->whereNumber('domainId')
            ->middleware(\App\Http\Middleware\RequirePermission::class.':settings.write');
        Route::post('/{domainId}/verify', [\App\Http\Controllers\Api\TenantDomainController::class, 'verify'])
            ->whereNumber('domainId')
            ->middleware(\App\Http\Middleware\RequirePermission::class.':settings.write');
        Route::post('/{domainId}/check', [\App\Http\Controllers\Api\TenantDomainController::class, 'check'])
            ->whereNumber('domainId')
            ->middleware(\App\Http\Middleware\RequirePermission::class.':settings.write');
    });

    // Tenant roles & permissions (matrix editable by Owner/Admin — enforced
    // in-controller too, since users.write is also granted to Manager).
    Route::get('/tenant-roles', [\App\Http\Controllers\Api\TenantRoleController::class, 'index'])
        ->middleware(\App\Http\Middleware\RequirePermission::class.':users.write');
    Route::put('/tenant-roles/{role}', [\App\Http\Controllers\Api\TenantRoleController::class, 'updatePermissions'])
        ->middleware(\App\Http\Middleware\RequirePermission::class.':users.write');

    // Version/correction chain endpoints
    Route::get('/invoices/{id}/correction-chain', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'getInvoiceCorrectionChain']);
    Route::get('/bills/{id}/correction-chain', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'getBillCorrectionChain']);
    Route::get('/journals/{id}/correction-chain', [\App\Http\Controllers\Api\DocumentCorrectionsController::class, 'getJournalCorrectionChain']);

    // ─── XBRL / Compliance Reports (optional, gated by feature flag) ──────────
    if (config('compliance.enabled', false)) {
        // Issue #100: reporting reads require accounting.view (previously ungated).
        Route::prefix('accounting/compliance')
            ->middleware(\App\Http\Middleware\RequirePermission::class.':accounting.view')
            ->group(function () {
                Route::get('/reports/{country_code}/{report_type}', [\App\Http\Controllers\Accounting\ComplianceController::class, 'exportReport']);
                Route::get('/reports/{country_code}/{report_type}/download', [\App\Http\Controllers\Accounting\ComplianceController::class, 'downloadReport']);
            });
    }

    $modules = app(\App\Modules\ModuleManager::class);

    foreach ($modules->tenantApiRouteFiles() as $routesFile) {
        require $routesFile;
    }

    // ─── Accounting Findings (ACC-RM-006/007) ──────────────────────────────────
    Route::prefix('accounting/findings')->group(function () {
        Route::get('/{entityId}', [\App\Http\Controllers\Api\AccountingFindingsController::class, 'index']);
        Route::get('/{entityId}/summary', [\App\Http\Controllers\Api\AccountingFindingsController::class, 'summary']);
        Route::post('/', [\App\Http\Controllers\Api\AccountingFindingsController::class, 'store']);
        Route::get('/detail/{id}', [\App\Http\Controllers\Api\AccountingFindingsController::class, 'show']);
        Route::post('/{id}/acknowledge', [\App\Http\Controllers\Api\AccountingFindingsController::class, 'acknowledge']);
        Route::post('/{id}/investigate', [\App\Http\Controllers\Api\AccountingFindingsController::class, 'investigate']);
        Route::post('/{id}/resolve', [\App\Http\Controllers\Api\AccountingFindingsController::class, 'resolve']);
        Route::post('/{id}/dismiss', [\App\Http\Controllers\Api\AccountingFindingsController::class, 'dismiss']);
        Route::post('/{id}/escalate', [\App\Http\Controllers\Api\AccountingFindingsController::class, 'escalate']);
    });

    // ─── Accounting Import (ACC-RM-018) ──────────────────────────────────────
    Route::prefix('accounting/import')->middleware(\App\Http\Middleware\RequirePermission::class.':accounting.write')->group(function () {
        Route::post('/stage', [\App\Http\Controllers\Api\AccountingImportController::class, 'stage']);
        Route::get('/{batchId}/status', [\App\Http\Controllers\Api\AccountingImportController::class, 'status']);
        Route::post('/{batchId}/commit', [\App\Http\Controllers\Api\AccountingImportController::class, 'commit']);
    });

    // ─── Multi-Step Approval Workflows (ACC-RM-016) ─────────────────────────
    Route::prefix('approval/workflows')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\ApprovalWorkflowController::class, 'index']);
        Route::get('/{id}', [\App\Http\Controllers\Api\ApprovalWorkflowController::class, 'show']);
        Route::post('/initiate', [\App\Http\Controllers\Api\ApprovalWorkflowController::class, 'initiate']);
        Route::post('/{instanceId}/approve-step', [\App\Http\Controllers\Api\ApprovalWorkflowController::class, 'approveStep']);
        Route::post('/{instanceId}/reject-step', [\App\Http\Controllers\Api\ApprovalWorkflowController::class, 'rejectStep']);
        Route::get('/my-pending', [\App\Http\Controllers\Api\ApprovalWorkflowController::class, 'myPending']);
        Route::get('/active', [\App\Http\Controllers\Api\ApprovalWorkflowController::class, 'active']);
    });

    // ─── Period Close (ACC-RM-004/005) ─────────────────────────────────────
    Route::prefix('accounting/periods')
        ->middleware([\App\Http\Middleware\ResolveEntityContext::class, \App\Http\Middleware\EnsureEntityAccess::class])
        ->group(function () {
            Route::get('/{periodId}/summary', [\App\Http\Controllers\Api\PeriodCloseController::class, 'summary']);
            Route::get('/{periodId}/validate', [\App\Http\Controllers\Api\PeriodCloseController::class, 'validatePeriod']);
            Route::post('/{periodId}/close', [\App\Http\Controllers\Api\PeriodCloseController::class, 'close'])
                ->middleware(\App\Http\Middleware\RequirePermission::class.':accounting.lock');
            Route::post('/{periodId}/reopen', [\App\Http\Controllers\Api\PeriodCloseController::class, 'reopen'])
                ->middleware(\App\Http\Middleware\RequirePermission::class.':accounting.lock');
        });

    // ─── Approval Workflow ────────────────────────────────────────────────────
    // Tenant-scoped approval requests for POS void/refund/return/discount.
    // Requires auth:sanctum (inherited from outer middleware group).
    Route::prefix('approvals')->group(function () {
        Route::post('/', [\App\Http\Controllers\ApprovalController::class, 'store']);
        Route::get('/', [\App\Http\Controllers\ApprovalController::class, 'index']);
        Route::get('/my-requests', [\App\Http\Controllers\ApprovalController::class, 'myRequests']);
        Route::get('/{id}', [\App\Http\Controllers\ApprovalController::class, 'show']);
        Route::post('/{id}/approve', [\App\Http\Controllers\ApprovalController::class, 'approve']);
        Route::post('/{id}/reject', [\App\Http\Controllers\ApprovalController::class, 'reject']);
    });

    // Tenant-scoped user notifications (in-app inbox + actions).
    // Requires auth:sanctum (inherited from outer middleware group).
    Route::prefix('notifications')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
        Route::get('/unread-count', [\App\Http\Controllers\Api\NotificationController::class, 'unreadCount']);
        Route::post('/mark-all-read', [\App\Http\Controllers\Api\NotificationController::class, 'markAllRead']);
        Route::post('/{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markRead']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\NotificationController::class, 'destroy']);
    });

    // Tags — free-form labels on invoices, quotations, sales orders, delivery
    // orders and customers. Powers the global search tag filter.
    Route::get('/tags', [\App\Http\Controllers\Api\TagController::class, 'index']);
    Route::post('/tags', [\App\Http\Controllers\Api\TagController::class, 'store']);
    Route::get('/tags/{subjectType}/{subjectId}', [\App\Http\Controllers\Api\TagController::class, 'forSubject']);
    Route::post('/tags/{subjectType}/{subjectId}', [\App\Http\Controllers\Api\TagController::class, 'attach']);
    Route::delete('/tags/{subjectType}/{subjectId}/{tagId}', [\App\Http\Controllers\Api\TagController::class, 'detach']);

    // Global search omnibox — documents, master data and tags.
    Route::get('/search', [\App\Http\Controllers\Api\TagController::class, 'search']);
    // Document tracing: resolve any reference to its whole document family.
    Route::get('/reference-trace', [\App\Http\Controllers\Api\ReferenceTraceController::class, 'trace']);
    // Document tracing: resolve any reference to its whole document family.
    Route::get('/reference-trace', [\App\Http\Controllers\Api\ReferenceTraceController::class, 'trace']);

    // ─── ADR-005 Reversals (§15.3) ──────────────────────────────────────────
    // Issue #90 (BLOCKER, RBAC-1): reversal routes must require
    // accounting.reverse permission — same pattern as sibling corrections
    // prefix and PeriodCloseController. Without this guard, any authenticated
    // tenant user can reverse a posted journal entry.
    Route::prefix('accounting/reversals')
        ->middleware(\App\Http\Middleware\RequirePermission::class.':accounting.reverse')
        ->group(function () {
            Route::post('/full', [\App\Http\Controllers\Api\ReversalController::class, 'fullReversal']);
            Route::post('/partial', [\App\Http\Controllers\Api\ReversalController::class, 'partialReversal']);
            Route::post('/schedule-auto', [\App\Http\Controllers\Api\ReversalController::class, 'scheduleAutoReversal']);
            Route::post('/execute-due', [\App\Http\Controllers\Api\ReversalController::class, 'executeDueAutoReversals']);
            Route::get('/relationship/{id}', [\App\Http\Controllers\Api\ReversalController::class, 'showRelationship']);
            Route::get('/original/{journalEntryId}', [\App\Http\Controllers\Api\ReversalController::class, 'forOriginal']);
        });

    // ─── ADR-005 Document Revisions (§27) ───────────────────────────────────
    Route::prefix('accounting/revisions')->group(function () {
        Route::get('/resolve-policy', [\App\Http\Controllers\Api\DocumentRevisionController::class, 'resolvePolicy']);
        Route::get('/{documentUuid}', [\App\Http\Controllers\Api\DocumentRevisionController::class, 'index']);
        Route::get('/{documentUuid}/effective', [\App\Http\Controllers\Api\DocumentRevisionController::class, 'effective']);
        Route::post('/', [\App\Http\Controllers\Api\DocumentRevisionController::class, 'store']);
        Route::post('/{id}/issue', [\App\Http\Controllers\Api\DocumentRevisionController::class, 'issue']);
        Route::post('/{id}/supersede', [\App\Http\Controllers\Api\DocumentRevisionController::class, 'supersede']);
    });

    // ─── ADR-005 Payment Executions (§21) ───────────────────────────────────
    Route::prefix('accounting/payment-executions')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\PaymentExecutionController::class, 'index']);
        Route::get('/{id}', [\App\Http\Controllers\Api\PaymentExecutionController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Api\PaymentExecutionController::class, 'store']);
        Route::post('/{id}/submit', [\App\Http\Controllers\Api\PaymentExecutionController::class, 'submit']);
        Route::post('/{id}/complete', [\App\Http\Controllers\Api\PaymentExecutionController::class, 'complete']);
        Route::post('/{id}/unknown-outcome', [\App\Http\Controllers\Api\PaymentExecutionController::class, 'markUnknownOutcome']);
        Route::post('/{id}/fail', [\App\Http\Controllers\Api\PaymentExecutionController::class, 'fail']);
    });

    // ─── ADR-005 Settlements (§22) ──────────────────────────────────────────
    Route::prefix('accounting/settlements')
        ->middleware([\App\Http\Middleware\ResolveEntityContext::class, \App\Http\Middleware\EnsureEntityAccess::class])
        ->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\SettlementController::class, 'index']);
            Route::get('/{id}', [\App\Http\Controllers\Api\SettlementController::class, 'show']);
            Route::post('/allocate', [\App\Http\Controllers\Api\SettlementController::class, 'allocate'])
                ->middleware(\App\Http\Middleware\RequirePermission::class.':accounting.write');
            Route::post('/{id}/reverse', [\App\Http\Controllers\Api\SettlementController::class, 'reverse'])
                ->middleware(\App\Http\Middleware\RequirePermission::class.':accounting.reverse');
            Route::get('/unapplied-cash/list', [\App\Http\Controllers\Api\SettlementController::class, 'unappliedCash']);
        });

    // ─── ADR-005 Correction Cases (§56-59) ─────────────────────────────────
    Route::prefix('accounting/correction-cases')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\CorrectionCaseController::class, 'index']);
        Route::get('/{id}', [\App\Http\Controllers\Api\CorrectionCaseController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Api\CorrectionCaseController::class, 'store']);
        Route::post('/{id}/advance', [\App\Http\Controllers\Api\CorrectionCaseController::class, 'advance']);
        Route::post('/{id}/complete', [\App\Http\Controllers\Api\CorrectionCaseController::class, 'complete']);
    });
});

// Auditor Portal Routes (standalone with own middleware)
require __DIR__.'/auditor.php';
