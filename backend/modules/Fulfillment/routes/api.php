<?php

use App\Http\Middleware\RequirePermission;
use Illuminate\Support\Facades\Route;
use Modules\Fulfillment\Http\Controllers\AdminFulfillmentController;
use Modules\Fulfillment\Http\Controllers\FulfillmentController;
use Modules\Fulfillment\Http\Controllers\ProviderController;

/*
|--------------------------------------------------------------------------
| Fulfillment API Routes
|--------------------------------------------------------------------------
|
| Issue #143 (BLOCKER) remediation:
|
| 1. The old "public" group used ->middleware(['api', 'tenant']), but NO
|    'tenant' alias exists in bootstrap/app.php — every request to the whole
|    group crashed at middleware resolution. These module routes are loaded
|    from routes/tenant.php INSIDE the authenticated tenant group, which
|    already applies ['api', 'auth:sanctum', SetTenantFromUserPreference,
|    EnsureTenantSearchPath, SyncTenantUserFromCentralAuth, SubstituteBindings]
|    under the api/v1 prefix. So the group inherits real auth + tenant
|    resolution; the broken/redundant ['api','tenant'] is removed.
|
| 2. Carrier-credential CRUD (store/update/destroy/testCredentials) is REMOVED
|    from ProviderController's group. That capability already exists, correctly,
|    in AdminFulfillmentController. ProviderController keeps only read actions
|    (index/show/available-carriers) plus the storefront-appropriate rates and
|    shipment/tracking lookups via FulfillmentController.
|
| 3. AdminFulfillmentController routes now carry RequirePermission:
|    reads -> fulfillment.view, shipment writes -> fulfillment.write,
|    carrier-credential CRUD -> fulfillment.settings (Owner/Admin only, since
|    these manage encrypted carrier API keys/secrets).
|
*/

// ─── Operational fulfillment routes (authenticated via parent tenant group) ──
Route::prefix('fulfillment')->group(function () {
    // Read-only carrier/provider lookups (NO credential CRUD here — see #143).
    Route::get('/providers', [ProviderController::class, 'index'])
        ->middleware(RequirePermission::using('fulfillment.view'));
    Route::get('/providers/available-carriers', [ProviderController::class, 'getAvailableCarriers'])
        ->middleware(RequirePermission::using('fulfillment.view'));

    // Issue #148: ShippingRule CRUD (rule-based carrier selection).
    Route::get('/shipping-rules', [\Modules\Fulfillment\Http\Controllers\ShippingRuleController::class, 'index'])
        ->middleware(RequirePermission::using('fulfillment.view'));
    Route::post('/shipping-rules', [\Modules\Fulfillment\Http\Controllers\ShippingRuleController::class, 'store'])
        ->middleware(RequirePermission::using('fulfillment.settings'));
    Route::put('/shipping-rules/{id}', [\Modules\Fulfillment\Http\Controllers\ShippingRuleController::class, 'update'])
        ->middleware(RequirePermission::using('fulfillment.settings'));
    Route::delete('/shipping-rules/{id}', [\Modules\Fulfillment\Http\Controllers\ShippingRuleController::class, 'destroy'])
        ->middleware(RequirePermission::using('fulfillment.settings'));
    Route::post('/shipping-rules/reorder', [\Modules\Fulfillment\Http\Controllers\ShippingRuleController::class, 'reorder'])
        ->middleware(RequirePermission::using('fulfillment.settings'));
    Route::get('/providers/{id}', [ProviderController::class, 'show'])
        ->middleware(RequirePermission::using('fulfillment.view'));

    // Rate calculation + shipment lifecycle.
    Route::post('/rates', [FulfillmentController::class, 'calculateRates'])
        ->middleware(RequirePermission::using('fulfillment.view'));
    Route::get('/shipments', [FulfillmentController::class, 'listShipments'])
        ->middleware(RequirePermission::using('fulfillment.view'));
    Route::get('/shipments/{id}', [FulfillmentController::class, 'getShipment'])
        ->middleware(RequirePermission::using('fulfillment.view'));
    Route::get('/shipments/{id}/tracking', [FulfillmentController::class, 'getTracking'])
        ->middleware(RequirePermission::using('fulfillment.view'));
    Route::post('/shipments', [FulfillmentController::class, 'createShipment'])
        ->middleware(RequirePermission::using('fulfillment.write'));
    // Issue #147: Sales delivery order -> Fulfillment shipment integration.
    Route::post('/delivery-orders/{deliveryOrderId}/shipment', [FulfillmentController::class, 'createShipmentForDeliveryOrder'])
        ->middleware(RequirePermission::using('fulfillment.write'));
    Route::post('/shipments/{id}/cancel', [FulfillmentController::class, 'cancelShipment'])
        ->middleware(RequirePermission::using('fulfillment.write'));
    Route::post('/shipments/{id}/sync-tracking', [FulfillmentController::class, 'syncTracking'])
        ->middleware(RequirePermission::using('fulfillment.write'));
});

// ─── Admin carrier-credential + shipment management ──────────────────────────
// Credential CRUD is gated by fulfillment.settings (Owner/Admin only).
Route::prefix('admin/fulfillment')->group(function () {
    Route::get('/providers', [AdminFulfillmentController::class, 'providers'])
        ->middleware(RequirePermission::using('fulfillment.view'));
    Route::get('/providers/available', [AdminFulfillmentController::class, 'availableCarriers'])
        ->middleware(RequirePermission::using('fulfillment.view'));
    Route::get('/providers/{id}', [AdminFulfillmentController::class, 'showProvider'])
        ->middleware(RequirePermission::using('fulfillment.view'));
    Route::post('/providers', [AdminFulfillmentController::class, 'createProvider'])
        ->middleware(RequirePermission::using('fulfillment.settings'));
    Route::put('/providers/{id}', [AdminFulfillmentController::class, 'updateProvider'])
        ->middleware(RequirePermission::using('fulfillment.settings'));
    Route::delete('/providers/{id}', [AdminFulfillmentController::class, 'deleteProvider'])
        ->middleware(RequirePermission::using('fulfillment.settings'));
    Route::post('/providers/{id}/test', [AdminFulfillmentController::class, 'testCredentials'])
        ->middleware(RequirePermission::using('fulfillment.settings'));
    Route::post('/providers/{id}/toggle', [AdminFulfillmentController::class, 'toggleProvider'])
        ->middleware(RequirePermission::using('fulfillment.settings'));

    Route::get('/shipments', [AdminFulfillmentController::class, 'shipments'])
        ->middleware(RequirePermission::using('fulfillment.view'));
    Route::get('/shipments/{id}', [AdminFulfillmentController::class, 'showShipment'])
        ->middleware(RequirePermission::using('fulfillment.view'));
    Route::post('/shipments/{id}/create-label', [AdminFulfillmentController::class, 'createLabel'])
        ->middleware(RequirePermission::using('fulfillment.write'));
});
