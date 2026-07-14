<?php

use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\SalesOrderController;
use Modules\Sales\Http\Controllers\DeliveryOrderController;

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

        // Sales Orders — Core lifecycle
        Route::apiResource('sales/orders', SalesOrderController::class);

        // Lifecycle transitions
        Route::post('/sales/orders/{id}/confirm', [SalesOrderController::class, 'confirm']);
        Route::post('/sales/orders/{id}/allocate', [SalesOrderController::class, 'allocate']);
        Route::post('/sales/orders/{id}/fulfil', [SalesOrderController::class, 'fulfil']);
        Route::post('/sales/orders/{id}/cancel', [SalesOrderController::class, 'cancel']);

        // Linked documents
        Route::post('/sales/orders/{id}/invoice', [SalesOrderController::class, 'createInvoice']);
        Route::get('/sales/orders/{id}/invoice-links', [SalesOrderController::class, 'invoiceLinks']);
        Route::get('/sales/orders/{id}/channel-references', [SalesOrderController::class, 'channelReferences']);

        // Lifecycle history (immutable audit)
        Route::get('/sales/orders/{id}/history', [SalesOrderController::class, 'history']);

        // Credit preview
        Route::get('/sales/orders/{id}/credit-preview', [SalesOrderController::class, 'creditPreview']);
        Route::post('/sales/orders/{id}/bill-by-delivered', [SalesOrderController::class, 'setBillByDelivered']);

        // Delivery Orders
        Route::apiResource('sales/delivery-orders', DeliveryOrderController::class);
        Route::post('/sales/delivery-orders/{id}/confirm', [DeliveryOrderController::class, 'confirm']);
        Route::post('/sales/delivery-orders/{id}/cancel', [DeliveryOrderController::class, 'cancel']);
        Route::post('/sales/delivery-orders/bulk-delete', [DeliveryOrderController::class, 'bulkDestroy']);
        Route::post('/sales/delivery-orders/import', [DeliveryOrderController::class, 'import']);
        Route::get('/sales/delivery-orders/template', [DeliveryOrderController::class, 'template']);
        Route::get('/sales/orders/{id}/deliveries', [DeliveryOrderController::class, 'forSalesOrder']);
    });
});