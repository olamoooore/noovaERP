<?php

use App\Http\Controllers\Api\DocumentAuditController;
use App\Http\Controllers\Api\DocumentStockMovesController;
use App\Http\Controllers\Api\StockCogsBadgeController;
use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\DeliveryOrderController;
use Modules\Sales\Http\Controllers\SalesNotificationPreferenceController;
use Modules\Sales\Http\Controllers\SalesOrderController;

Route::middleware([
    'auth:sanctum',
    'entitlement:sales',
    \App\Http\Middleware\SetTenantFromUserPreference::class,
    \App\Http\Middleware\EnsureTenantSearchPath::class,
    \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
])->group(function () {

    // Issue #903: the user-facing mute surface for sales notifications.
    // Self-scoped (always the authenticated user) and entity-independent, so
    // it sits outside ResolveEntityContext/EnsureEntityAccess.
    Route::get('/notifications/preferences', [SalesNotificationPreferenceController::class, 'index']);
    Route::put('/notifications/preferences', [SalesNotificationPreferenceController::class, 'update']);

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
            // Issue #407: lock/unlock a confirmed order (Odoo parity).
            Route::post('/sales/orders/{id}/lock', [SalesOrderController::class, 'lock']);
            Route::post('/sales/orders/{id}/unlock', [SalesOrderController::class, 'unlock']);
            Route::post('/sales/orders/{id}/cancel', [SalesOrderController::class, 'cancel']);
            Route::post('/sales/orders/{id}/reset-to-draft', [SalesOrderController::class, 'resetToDraft'])
                ->middleware(\App\Http\Middleware\RequirePermission::using('documents.reset_to_draft'));

            // Linked documents (mutating)
            Route::post('/sales/orders/{id}/invoice', [SalesOrderController::class, 'createInvoice']);
            // Issue #154: the credit-note path the cancel error points at.
            Route::post('/sales/orders/{id}/credit-note', [SalesOrderController::class, 'creditNote']);
            // Issue #152: blanket order writes/releases require sales.write.
            Route::post('/sales/blanket-orders', [\Modules\Sales\Http\Controllers\SalesBlanketOrderController::class, 'store']);
            Route::post('/sales/blanket-orders/{blanketOrder}/release', [\Modules\Sales\Http\Controllers\SalesBlanketOrderController::class, 'release']);

            // Issue #151: recurring sales lifecycle (writes).
            Route::post('/sales/orders/{salesOrder}/recurrence', [\Modules\Sales\Http\Controllers\SalesOrderRecurrenceController::class, 'store']);
            Route::post('/sales/recurrences/{recurrence}/pause', [\Modules\Sales\Http\Controllers\SalesOrderRecurrenceController::class, 'pause']);
            Route::post('/sales/recurrences/{recurrence}/resume', [\Modules\Sales\Http\Controllers\SalesOrderRecurrenceController::class, 'resume']);
            Route::post('/sales/recurrences/{recurrence}/cancel', [\Modules\Sales\Http\Controllers\SalesOrderRecurrenceController::class, 'cancel']);
            Route::post('/sales/orders/{id}/bill-by-delivered', [SalesOrderController::class, 'setBillByDelivered']);
        });

        Route::middleware(\App\Http\Middleware\RequirePermission::class.':sales.view')->group(function () {
            Route::get('/sales/orders/{id}/invoice-links', [SalesOrderController::class, 'invoiceLinks']);
            Route::get('/sales/orders/{id}/channel-references', [SalesOrderController::class, 'channelReferences']);

            // Lifecycle history (immutable audit)
            Route::get('/sales/orders/{id}/history', [SalesOrderController::class, 'history']);

            // Issue #905 (PCU1): read-only audit tab under the sales-order
            // view gate (sales.view opens the record).
            Route::get('/sales/orders/{subjectId}/audit', [DocumentAuditController::class, 'index'])
                ->whereNumber('subjectId')
                ->defaults('auditSubject', 'sales_order');
            Route::get('/sales/orders/{subjectId}/audit/export', [DocumentAuditController::class, 'export'])
                ->whereNumber('subjectId')
                ->defaults('auditSubject', 'sales_order');
            // Issue #690 (INV-UX4): the order's warehouse trail, gated the
            // same way as the audit tab (sales.view opens the record).
            Route::get('/sales/orders/{subjectId}/stock-moves', [DocumentStockMovesController::class, 'index'])
                ->whereNumber('subjectId')
                ->defaults('stockMovesSubject', 'sales_order');
            // Issue #696 (INV-D3): stock & COGS reconciliation badge for the
            // order header — same read gate as the trail tab.
            Route::get('/sales/orders/{subjectId}/stock-cogs-badge', [StockCogsBadgeController::class, 'index'])
                ->whereNumber('subjectId')
                ->defaults('stockCogsSubject', 'sales_order');
            // Issue #150: sales-pipeline analytics (pre-invoice).
            Route::get('/sales/analytics/pipeline', [\Modules\Sales\Http\Controllers\SalesPipelineAnalyticsController::class, 'index']);

            // Issue #152: blanket orders / call-off agreements (reads).
            Route::get('/sales/blanket-orders', [\Modules\Sales\Http\Controllers\SalesBlanketOrderController::class, 'index']);
            Route::get('/sales/blanket-orders/{blanketOrder}', [\Modules\Sales\Http\Controllers\SalesBlanketOrderController::class, 'show']);

            // Issue #151: recurring sales (reads).
            Route::get('/sales/recurrences', [\Modules\Sales\Http\Controllers\SalesOrderRecurrenceController::class, 'index']);

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
            // Issue #465: pre-submit availability (on hand / reserved / available)
            // for the standalone DO create page. Must precede the apiResource
            // below so "stock-check" is not captured by the {id} show route.
            Route::get('/sales/delivery-orders/stock-check', [DeliveryOrderController::class, 'stockCheck']);
            // Issue #687 (UX1): bulk per-line availability for document line
            // editors and the order-confirm shortage summary. Same path as the
            // #465 check, bulk POST body (warehouse_id + product lines).
            Route::post('/sales/delivery-orders/stock-check', [DeliveryOrderController::class, 'bulkStockCheck']);
            Route::get('/sales/orders/{id}/deliveries', [DeliveryOrderController::class, 'forSalesOrder']);

            // Issue #905 (PCU1): read-only audit tab under the DO view gate.
            Route::get('/sales/delivery-orders/{subjectId}/audit', [DocumentAuditController::class, 'index'])
                ->whereNumber('subjectId')
                ->defaults('auditSubject', 'delivery_order');
            Route::get('/sales/delivery-orders/{subjectId}/audit/export', [DocumentAuditController::class, 'export'])
                ->whereNumber('subjectId')
                ->defaults('auditSubject', 'delivery_order');
            // Issue #690 (INV-UX4): the delivery order's warehouse trail.
            Route::get('/sales/delivery-orders/{subjectId}/stock-moves', [DocumentStockMovesController::class, 'index'])
                ->whereNumber('subjectId')
                ->defaults('stockMovesSubject', 'delivery_order');
            // Issue #696 (INV-D3): stock & COGS reconciliation badge for the
            // delivery order header — same read gate as the trail tab.
            Route::get('/sales/delivery-orders/{subjectId}/stock-cogs-badge', [StockCogsBadgeController::class, 'index'])
                ->whereNumber('subjectId')
                ->defaults('stockCogsSubject', 'delivery_order');
        });

        Route::apiResource('sales/delivery-orders', DeliveryOrderController::class)
            ->only(['index', 'show'])
            ->middleware(\App\Http\Middleware\RequirePermission::class.':sales.view');
        Route::apiResource('sales/delivery-orders', DeliveryOrderController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware(\App\Http\Middleware\RequirePermission::class.':sales.write');

        // Confirming a delivery order releases stock/COGS — accounting-only
        // (Owner/Admin/Accountant), same gate as invoice posting.
        Route::post('/sales/delivery-orders/{id}/confirm', [DeliveryOrderController::class, 'confirm'])
            ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.post'));

        // Lifecycle transitions + bulk operations
        Route::middleware(\App\Http\Middleware\RequirePermission::class.':sales.write')->group(function () {
            // Delivery Order → Invoice conversion (accounting-origin path).
            Route::post('/sales/delivery-orders/{id}/create-invoice', [DeliveryOrderController::class, 'createInvoice']);
            // Delivery-order approval (reviewer actions).
            Route::post('/sales/delivery-orders/{id}/approve', [DeliveryOrderController::class, 'approve']);
            Route::post('/sales/delivery-orders/{id}/reject-approval', [DeliveryOrderController::class, 'rejectApproval']);
            // Issue #177: signed copy upload for the physical confirmation path.
            Route::post('/sales/delivery-orders/{id}/signed-document', [DeliveryOrderController::class, 'uploadSignedDocument']);
            // Customer portal: send the DO to the customer for signing/evidence.
            Route::post('/sales/delivery-orders/{id}/customer-link', [DeliveryOrderController::class, 'issueCustomerLink']);
            // Issue #153: download the signed POD, gated the same way as the
            // delivery-orders detail endpoint.
            Route::get('/sales/delivery-orders/{id}/signed-document', [DeliveryOrderController::class, 'downloadSignedDocument'])
                ->name('sales.delivery-orders.signed-document.download');
            Route::post('/sales/delivery-orders/{id}/complete', [DeliveryOrderController::class, 'complete']);
            Route::post('/sales/delivery-orders/{id}/reverse', [DeliveryOrderController::class, 'reverse']);
            Route::post('/sales/delivery-orders/{id}/cancel', [DeliveryOrderController::class, 'cancel']);
            // Issue #178/#179: rejection with auto-versioned replacement draft.
            Route::post('/sales/delivery-orders/{id}/reject', [DeliveryOrderController::class, 'reject']);
            Route::post('/sales/delivery-orders/{id}/reset-to-draft', [DeliveryOrderController::class, 'resetToDraft'])
                ->middleware(\App\Http\Middleware\RequirePermission::using('documents.reset_to_draft'));
            Route::post('/sales/delivery-orders/bulk-delete', [DeliveryOrderController::class, 'bulkDestroy']);
            Route::post('/sales/delivery-orders/import', [DeliveryOrderController::class, 'import']);
        });
    });
});
