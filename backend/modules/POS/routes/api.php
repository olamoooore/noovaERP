<?php

use Illuminate\Support\Facades\Route;
use Modules\POS\Http\Controllers\PosOrderController;
use Modules\POS\Http\Controllers\PosProductController;
use Modules\POS\Http\Controllers\PosBarcodeController;
use Modules\POS\Http\Controllers\PosReportController;
use Modules\POS\Http\Controllers\PosWarehouseController;
use Modules\POS\Http\Controllers\PosSessionController;
use Modules\POS\Http\Controllers\PosTerminalController;
use Modules\POS\Http\Controllers\PosHeldOrderController;
use Modules\POS\Http\Controllers\PosPaymentMethodController;
use Modules\POS\Http\Controllers\PosSettingController;
use App\Http\Controllers\SalesOrderController;

Route::middleware(['auth:sanctum', 'entitlement:pos', 'ensure.tenant.search_path'])->group(function () {
    Route::get('/pos/health', function () {
        return ['module' => 'pos', 'ok' => true];
    });
    
    // =====================================================
    // UNIFIED SALES ORDERS (POS + Web)
    // =====================================================
    
    Route::get('/sales/orders', [SalesOrderController::class, 'index']);

    // =====================================================
    // TERMINALS & SESSIONS (Enterprise POS)
    // =====================================================
    
    // Terminals
    Route::get('/pos/terminals', [PosTerminalController::class, 'index']);
    Route::post('/pos/terminals', [PosTerminalController::class, 'store']);
    Route::get('/pos/terminals/{terminal}', [PosTerminalController::class, 'show']);
    Route::put('/pos/terminals/{terminal}', [PosTerminalController::class, 'update']);
    Route::delete('/pos/terminals/{terminal}', [PosTerminalController::class, 'destroy']);
    
    // Sessions
    Route::get('/pos/sessions', [PosSessionController::class, 'index']);
    Route::get('/pos/sessions/current', [PosSessionController::class, 'currentSession']);
    Route::post('/pos/sessions/open', [PosSessionController::class, 'openSession']);
    Route::get('/pos/sessions/{sessionId}', [PosSessionController::class, 'show']);
    Route::post('/pos/sessions/{sessionId}/close', [PosSessionController::class, 'closeSession']);
    Route::post('/pos/sessions/{sessionId}/cash-movement', [PosSessionController::class, 'cashMovement']);
    
    // Held Orders (Park/Recall)
    Route::get('/pos/held-orders', [PosHeldOrderController::class, 'index']);
    Route::post('/pos/held-orders', [PosHeldOrderController::class, 'store']);
    Route::get('/pos/held-orders/{heldOrder}', [PosHeldOrderController::class, 'show']);
    Route::delete('/pos/held-orders/{heldOrder}', [PosHeldOrderController::class, 'destroy']);
    
    // Payment Methods
    Route::get('/pos/payment-methods', [PosPaymentMethodController::class, 'index']);
    Route::post('/pos/payment-methods', [PosPaymentMethodController::class, 'store']);
    Route::put('/pos/payment-methods/{paymentMethod}', [PosPaymentMethodController::class, 'update']);
    Route::delete('/pos/payment-methods/{paymentMethod}', [PosPaymentMethodController::class, 'destroy']);

    // =====================================================
    // PRODUCTS & WAREHOUSES
    // =====================================================
    
    // Product lookup for barcode scanning
    Route::get('/pos/products/lookup', [PosProductController::class, 'lookup']);

    // Warehouses
    Route::get('/pos/warehouses', [PosWarehouseController::class, 'index']);

    // =====================================================
    // ORDERS & PAYMENTS
    // =====================================================
    
    // POS Orders
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
    Route::delete('/pos/orders/{posOrder}', [PosOrderController::class, 'destroy']);

    // =====================================================
    // DASHBOARD & REPORTS
    // =====================================================
    
    // POS Dashboard
    Route::get('/pos/dashboard', [PosOrderController::class, 'dashboard']);

    // POS Reports
    Route::get('/pos/reports', [PosOrderController::class, 'reports']);

    // =====================================================
    // BARCODES
    // =====================================================
    
    // Barcodes
    Route::get('/pos/barcodes', [PosBarcodeController::class, 'index']);
    Route::post('/pos/barcodes/print', [PosBarcodeController::class, 'print']);
    Route::get('/pos/barcodes/settings', [PosBarcodeController::class, 'settings']);
    Route::post('/pos/barcodes/settings', [PosBarcodeController::class, 'updateSettings']);

    // =====================================================
    // POS SETTINGS
    // =====================================================
    
    Route::get('/pos/settings', [PosSettingController::class, 'index']);
    Route::put('/pos/settings', [PosSettingController::class, 'update']);
    Route::post('/pos/settings/reset', [PosSettingController::class, 'reset']);

    // =====================================================
    // OFFLINE SYNC
    // =====================================================

    Route::post('/pos/sync/queue',              [\Modules\POS\Http\Controllers\PosSyncController::class, 'queue']);
    Route::get('/pos/sync/pending',             [\Modules\POS\Http\Controllers\PosSyncController::class, 'pending']);
    Route::get('/pos/sync/stats',               [\Modules\POS\Http\Controllers\PosSyncController::class, 'stats']);
    Route::post('/pos/sync/all',                [\Modules\POS\Http\Controllers\PosSyncController::class, 'syncAll']);
    Route::post('/pos/sync/{id}',               [\Modules\POS\Http\Controllers\PosSyncController::class, 'sync']);
    Route::post('/pos/sync/{id}/retry',         [\Modules\POS\Http\Controllers\PosSyncController::class, 'retry']);
    Route::post('/pos/sync/{id}/resolve',       [\Modules\POS\Http\Controllers\PosSyncController::class, 'resolveConflict']);

    // =====================================================
    // CUSTOMER DISPLAY
    // =====================================================

    Route::get('/pos/display/{terminal_id}',    [\Modules\POS\Http\Controllers\CustomerDisplayController::class, 'show']);
    Route::post('/pos/display/{terminal_id}/token', [\Modules\POS\Http\Controllers\CustomerDisplayController::class, 'issueToken']);
});
