<?php

use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\DeliveryOrderController;
use Modules\Sales\Http\Controllers\SalesOrderController;

Route::middleware([
    'auth:sanctum',
    'entitlement:sales',
    \App\Http\Middleware\SetTenantFromUserPreference::class,
    \App\Http\Middleware\EnsureTenantSearchPath::class,
    \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
])->group(function () {

    Route::middleware([
        \App\Http\Middleware\ResolveEntityContext::class,
        \App\Http\Middleware\EnsureEntityAccess::class,
    ])->group(function () {

        // Sales Orders — Core lifecycle (read vs. write split so each action
        // requires the correct one of the platform's seeded sales.view/sales.write permissions)
        Route::apiResource('sales/orders', SalesOrderController::class)
            ->only(['index', 'show'])
            ->middleware(\App\Http\Middleware\RequirePermission::class.':sales.view');
        Route::apiResource('sales/orders', SalesOrderController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware(\App\Http\Middleware\RequirePermission::class.':sales.write');

        // Lifecycle transitions
        Route::middleware(\App\Http\Middleware\RequirePermission::class.':sales.write')->group(function () {
            Route::post('/sales/orders/{id}/confirm', [SalesOrderController::class, 'confirm']);
            Route::post('/sales/orders/{id}/allocate', [SalesOrderController::class, 'allocate']);
            Route::post('/sales/orders/{id}/fulfil', [SalesOrderController::class, 'fulfil']);
            Route::post('/sales/orders/{id}/cancel', [SalesOrderController::class, 'cancel']);
            Route::post('/sales/orders/{id}/reset-to-draft', [SalesOrderController::class, 'resetToDraft']);

            // Linked documents (mutating)
            Route::post('/sales/orders/{id}/invoice', [SalesOrderController::class, 'createInvoice']);
            Route::post('/sales/orders/{id}/bill-by-delivered', [SalesOrderController::class, 'setBillByDelivered']);
        });

        Route::middleware(\App\Http\Middleware\RequirePermission::class.':sales.view')->group(function () {
            Route::get('/sales/orders/{id}/invoice-links', [SalesOrderController::class, 'invoiceLinks']);
            Route::get('/sales/orders/{id}/channel-references', [SalesOrderController::class, 'channelReferences']);

            // Lifecycle history (immutable audit)
            Route::get('/sales/orders/{id}/history', [SalesOrderController::class, 'history']);

            // Credit preview
            Route::get('/sales/orders/{id}/credit-preview', [SalesOrderController::class, 'creditPreview']);
        });

        // Delivery Orders — same read/write permission split as Sales Orders above
        // (issues #175 / #145: these routes previously had no RequirePermission at
        // all, so any user with the tenant 'sales' entitlement could complete,
        // reverse or bulk-delete delivery orders regardless of role).
        Route::middleware(\App\Http\Middleware\RequirePermission::class.':sales.view')->group(function () {
            Route::get('/sales/delivery-orders/export', [DeliveryOrderController::class, 'export']);
            Route::get('/sales/delivery-orders/template', [DeliveryOrderController::class, 'template']);
            Route::get('/sales/orders/{id}/deliveries', [DeliveryOrderController::class, 'forSalesOrder']);
        });

        Route::apiResource('sales/delivery-orders', DeliveryOrderController::class)
            ->only(['index', 'show'])
            ->middleware(\App\Http\Middleware\RequirePermission::class.':sales.view');
        Route::apiResource('sales/delivery-orders', DeliveryOrderController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware(\App\Http\Middleware\RequirePermission::class.':sales.write');

        // Lifecycle transitions + bulk operations
        Route::middleware(\App\Http\Middleware\RequirePermission::class.':sales.write')->group(function () {
            Route::post('/sales/delivery-orders/{id}/confirm', [DeliveryOrderController::class, 'confirm']);
            Route::post('/sales/delivery-orders/{id}/complete', [DeliveryOrderController::class, 'complete']);
            Route::post('/sales/delivery-orders/{id}/reverse', [DeliveryOrderController::class, 'reverse']);
            Route::post('/sales/delivery-orders/{id}/cancel', [DeliveryOrderController::class, 'cancel']);
            Route::post('/sales/delivery-orders/{id}/reset-to-draft', [DeliveryOrderController::class, 'resetToDraft']);
            Route::post('/sales/delivery-orders/bulk-delete', [DeliveryOrderController::class, 'bulkDestroy']);
            Route::post('/sales/delivery-orders/import', [DeliveryOrderController::class, 'import']);
        });
    });
});
