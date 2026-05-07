<?php

use Illuminate\Support\Facades\Route;
use Modules\POS\Http\Controllers\PosOrderController;
use Modules\POS\Http\Controllers\PosProductController;
use Modules\POS\Http\Controllers\PosBarcodeController;
use Modules\POS\Http\Controllers\PosWarehouseController;
use Modules\POS\Http\Controllers\PosSessionController;
use Modules\POS\Http\Controllers\PosTerminalController;
use Modules\POS\Http\Controllers\PosPaymentMethodController;
use Modules\POS\Http\Controllers\PosSettingController;
use Modules\POS\Http\Controllers\PosSyncController;
use Modules\POS\Http\Controllers\CustomerDisplayController;
use Modules\POS\Http\Controllers\PosHeldOrderController;
use Modules\POS\Http\Controllers\ReceiptSettingsController;
use Modules\POS\Http\Controllers\ReceiptTemplateController;

Route::middleware(['auth:sanctum', 'feature:pos'])->group(function () {
    Route::get('/pos/health', function () {
        return ['module' => 'pos', 'ok' => true];
    });

    // Product lookup for barcode scanning
    Route::get('/pos/products/lookup', [PosProductController::class, 'lookup']);

    // Warehouses
    Route::get('/pos/warehouses', [PosWarehouseController::class, 'index']);

    // Sessions and terminals
    Route::get('/pos/terminals', [PosSessionController::class, 'terminals']);
    Route::post('/pos/terminals', [PosTerminalController::class, 'store']);
    Route::put('/pos/terminals/{terminal}', [PosTerminalController::class, 'update']);
    Route::delete('/pos/terminals/{terminal}', [PosTerminalController::class, 'destroy']);

    Route::get('/pos/sessions', [PosSessionController::class, 'index']);
    Route::get('/pos/sessions/current', [PosSessionController::class, 'currentSession']);
    Route::post('/pos/sessions/open', [PosSessionController::class, 'openSession']);
    Route::get('/pos/sessions/{sessionId}', [PosSessionController::class, 'show']);
    Route::post('/pos/sessions/{sessionId}/close', [PosSessionController::class, 'closeSession']);
    Route::get('/pos/sessions/{sessionId}/close-summary', [PosSessionController::class, 'getCloseSummary']);
    Route::post('/pos/sessions/{sessionId}/cash-movement', [PosSessionController::class, 'cashMovement']);
    Route::post('/pos/sessions/{sessionId}/display-event', [PosSessionController::class, 'displayEvent']);

    // POS Orders
    Route::get('/pos/sales/orders', [PosOrderController::class, 'index']);
    Route::get('/pos/orders', [PosOrderController::class, 'index']);
    Route::post('/pos/orders', [PosOrderController::class, 'store']);
    Route::get('/pos/orders/{posOrder}', [PosOrderController::class, 'show']);
    Route::post('/pos/orders/{posOrder}/pay', [PosOrderController::class, 'pay']);
    Route::post('/pos/orders/{posOrder}/cancel', [PosOrderController::class, 'cancel']);
    Route::post('/pos/orders/{posOrder}/void', [PosOrderController::class, 'void']);
    Route::post('/pos/orders/{posOrder}/refund', [PosOrderController::class, 'refund']);
    Route::post('/pos/orders/{posOrder}/return', [PosOrderController::class, 'createReturn']);
    Route::get('/pos/orders/{posOrder}/receipt', [PosOrderController::class, 'receipt']);
    Route::get('/pos/orders/{posOrder}/invoice', [PosOrderController::class, 'invoice']);
    Route::post('/pos/orders/{posOrder}/print-receipt', [PosOrderController::class, 'printReceipt']);
    Route::post('/pos/orders/{posOrder}/print-labels', [PosOrderController::class, 'printLabels']);
    Route::delete('/pos/orders/{posOrder}', [PosOrderController::class, 'destroy']);

    // POS Dashboard
    Route::get('/pos/dashboard', [PosOrderController::class, 'dashboard']);

    // POS Reports
    Route::get('/pos/reports', [PosOrderController::class, 'reports']);

    // Barcodes
    Route::get('/pos/barcodes', [PosBarcodeController::class, 'index']);
    Route::post('/pos/barcodes/print', [PosBarcodeController::class, 'print']);
    Route::get('/pos/barcodes/settings', [PosBarcodeController::class, 'settings']);
    Route::post('/pos/barcodes/settings', [PosBarcodeController::class, 'updateSettings']);

    // POS settings and payment methods
    Route::get('/pos/settings', [PosSettingController::class, 'index']);
    Route::put('/pos/settings', [PosSettingController::class, 'update']);
    Route::post('/pos/settings/reset', [PosSettingController::class, 'reset']);

    Route::get('/pos/payment-methods', [PosPaymentMethodController::class, 'index']);
    Route::post('/pos/payment-methods', [PosPaymentMethodController::class, 'store']);
    Route::put('/pos/payment-methods/{paymentMethod}', [PosPaymentMethodController::class, 'update']);
    Route::delete('/pos/payment-methods/{paymentMethod}', [PosPaymentMethodController::class, 'destroy']);

    // Offline sync queue
    Route::post('/pos/sync/queue', [PosSyncController::class, 'queue']);
    Route::post('/pos/sync/all', [PosSyncController::class, 'syncAll']);
    Route::get('/pos/sync/pending', [PosSyncController::class, 'pending']);
    Route::get('/pos/sync/stats', [PosSyncController::class, 'stats']);
    Route::get('/pos/sync/{id}', [PosSyncController::class, 'show']);
    Route::post('/pos/sync/{id}', [PosSyncController::class, 'sync']);
    Route::post('/pos/sync/{id}/retry', [PosSyncController::class, 'retry']);
    Route::post('/pos/sync/retry/{id}', [PosSyncController::class, 'retry']);
    Route::post('/pos/sync/{id}/resolve', [PosSyncController::class, 'resolveConflict']);

    // Held orders
    Route::get('/pos/held-orders', [PosHeldOrderController::class, 'index']);
    Route::post('/pos/held-orders', [PosHeldOrderController::class, 'store']);
    Route::get('/pos/held-orders/{heldOrder}', [PosHeldOrderController::class, 'show']);
    Route::delete('/pos/held-orders/{heldOrder}', [PosHeldOrderController::class, 'destroy']);

    // Customer display
    Route::get('/pos/display/{terminalId}', [CustomerDisplayController::class, 'show']);
    Route::post('/pos/display/{terminalId}/token', [CustomerDisplayController::class, 'issueToken']);

    // Receipt Settings
    Route::get('/pos/receipt-settings', [ReceiptSettingsController::class, 'show']);
    Route::put('/pos/receipt-settings', [ReceiptSettingsController::class, 'update']);
    Route::post('/pos/receipt-settings/logo', [ReceiptSettingsController::class, 'uploadLogo']);
    Route::delete('/pos/receipt-settings/logo', [ReceiptSettingsController::class, 'deleteLogo']);

    // Receipt Templates (Builder)
    Route::get('/pos/receipt-templates/active', [ReceiptTemplateController::class, 'active']);
    Route::post('/pos/receipt-templates/{receiptTemplate}/activate', [ReceiptTemplateController::class, 'activate']);
    Route::apiResource('/pos/receipt-templates', ReceiptTemplateController::class);

});
