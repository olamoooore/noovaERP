<?php

use Illuminate\Support\Facades\Route;
use Modules\POS\Http\Controllers\PosOrderController;
use Modules\POS\Http\Controllers\PosProductController;
use Modules\POS\Http\Controllers\PosBarcodeController;
use Modules\POS\Http\Controllers\PosReportController;
use Modules\POS\Http\Controllers\PosWarehouseController;

Route::middleware(['auth:sanctum', 'feature:pos'])->group(function () {
    Route::get('/pos/health', function () {
        return ['module' => 'pos', 'ok' => true];
    });

    // Product lookup for barcode scanning
    Route::get('/pos/products/lookup', [PosProductController::class, 'lookup']);

    // Warehouses
    Route::get('/pos/warehouses', [PosWarehouseController::class, 'index']);

    // POS Orders
    Route::get('/pos/orders', [PosOrderController::class, 'index']);
    Route::post('/pos/orders', [PosOrderController::class, 'store']);
    Route::get('/pos/orders/{posOrder}', [PosOrderController::class, 'show']);
    Route::post('/pos/orders/{posOrder}/pay', [PosOrderController::class, 'pay']);

    // POS Dashboard
    Route::get('/pos/dashboard', [PosOrderController::class, 'dashboard']);

    // POS Reports
    Route::get('/pos/reports', [PosOrderController::class, 'reports']);

    // Barcodes
    Route::get('/pos/barcodes', [PosBarcodeController::class, 'index']);
    Route::post('/pos/barcodes/print', [PosBarcodeController::class, 'print']);
    Route::get('/pos/barcodes/settings', [PosBarcodeController::class, 'settings']);
    Route::post('/pos/barcodes/settings', [PosBarcodeController::class, 'updateSettings']);
});
