<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\BrandController;
use Modules\Inventory\Http\Controllers\InventoryReportController;
use Modules\Inventory\Http\Controllers\InventorySettingsController;
use Modules\Inventory\Http\Controllers\PurchaseOrderController;
use Modules\Inventory\Http\Controllers\StockController;
use Modules\Inventory\Http\Controllers\SupplierController;
use Modules\Inventory\Http\Controllers\WarehouseTransferController;
use App\Http\Controllers\Api\GoodsReceiptsController;

Route::middleware(['auth:sanctum', 'inventory.access'])->group(function () {
    // Brands
    Route::apiResource('brands', BrandController::class)->parameters([
        'brands' => 'brand_id',
    ]);

    // Suppliers
    Route::apiResource('suppliers', SupplierController::class)->parameters([
        'suppliers' => 'supplier_id',
    ]);

    // Purchase Orders
    Route::apiResource('purchase-orders', PurchaseOrderController::class)->parameters([
        'purchase-orders' => 'purchase_order_id',
    ]);
    Route::post('/purchase-orders/{purchase_order_id}/submit', [PurchaseOrderController::class, 'submit']);
    Route::post('/purchase-orders/{purchase_order_id}/approve', [PurchaseOrderController::class, 'approve']);
    Route::post('/purchase-orders/{purchase_order_id}/mark-ordered', [PurchaseOrderController::class, 'markOrdered']);
    Route::post('/purchase-orders/{purchase_order_id}/receive', [PurchaseOrderController::class, 'receive']);
    Route::post('/purchase-orders/{purchase_order_id}/cancel', [PurchaseOrderController::class, 'cancel']);

    // Goods Receipts
    Route::get('/goods-receipts', [GoodsReceiptsController::class, 'index']);
    Route::get('/goods-receipts/{goodsReceipt}', [GoodsReceiptsController::class, 'show']);
    Route::post('/goods-receipts', [GoodsReceiptsController::class, 'store']);
    Route::post('/goods-receipts/{goodsReceipt}/confirm', [GoodsReceiptsController::class, 'confirm']);
    Route::post('/goods-receipts/{goodsReceipt}/cancel', [GoodsReceiptsController::class, 'cancel']);

    // Warehouse Transfers
    Route::apiResource('warehouse-transfers', WarehouseTransferController::class)->parameters([
        'warehouse-transfers' => 'warehouse_transfer_id',
    ]);
    Route::post('/warehouse-transfers/{warehouse_transfer_id}/submit', [WarehouseTransferController::class, 'submit']);
    Route::post('/warehouse-transfers/{warehouse_transfer_id}/ship', [WarehouseTransferController::class, 'ship']);
    Route::post('/warehouse-transfers/{warehouse_transfer_id}/receive', [WarehouseTransferController::class, 'receive']);
    Route::post('/warehouse-transfers/{warehouse_transfer_id}/cancel', [WarehouseTransferController::class, 'cancel']);

    // Stock Operations
    Route::prefix('stock')->group(function () {
        Route::get('/warehouse/{warehouseId}', [StockController::class, 'warehouseStock']);
        Route::get('/product/{productId}', [StockController::class, 'productStock']);
        Route::get('/movements', [StockController::class, 'movements']);
        Route::get('/adjustment-reasons', [StockController::class, 'adjustmentReasons']);
        Route::get('/low-stock', [StockController::class, 'lowStock']);
        Route::get('/out-of-stock', [StockController::class, 'outOfStock']);
        Route::post('/check-availability', [StockController::class, 'checkAvailability']);
        Route::put('/level/{productId}/{warehouseId}', [StockController::class, 'updateStockLevel']);
    });

    // Inventory Reports
    Route::prefix('inventory')->group(function () {
        Route::get('/dashboard', [InventoryReportController::class, 'dashboard']);
        Route::get('/valuation', [InventoryReportController::class, 'valuation']);
        Route::get('/reports/low-stock', [InventoryReportController::class, 'lowStockReport']);
        Route::get('/reports/out-of-stock', [InventoryReportController::class, 'outOfStockReport']);
        Route::get('/reports/warehouse/{warehouseId}', [InventoryReportController::class, 'warehouseReport']);
        Route::get('/reports/movements', [InventoryReportController::class, 'movementReport']);
        Route::get('/reports/ageing', [InventoryReportController::class, 'ageingReport']);
        Route::get('/settings', [InventorySettingsController::class, 'show']);
        Route::put('/settings', [InventorySettingsController::class, 'update']);
    });
});
