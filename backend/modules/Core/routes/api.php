<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AccountingController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductVariantController;
use App\Http\Controllers\Api\ProductUnitController;
use App\Http\Controllers\Api\ProductAttributeController;
use App\Http\Controllers\Api\ProductFeatureController;
use App\Http\Controllers\Api\ProductCustomFieldController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\FeatureController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TaxController;
use App\Http\Controllers\Api\Auth\AuthController;

// Auth
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    // Settings
    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings', [SettingsController::class, 'update']);

    // Taxes
    Route::get('/taxes', [TaxController::class, 'index']);
    Route::post('/taxes', [TaxController::class, 'store']);
    Route::put('/taxes/{tax}', [TaxController::class, 'update']);
    Route::delete('/taxes/{tax}', [TaxController::class, 'destroy']);
    
    // Features
    Route::get('/features', [FeatureController::class, 'index']);
    Route::post('/features/{id}/toggle', [FeatureController::class, 'toggle']);

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Warehouses
    Route::apiResource('warehouses', \App\Http\Controllers\Api\WarehouseController::class);

    // Users
    Route::get('/users', [\App\Http\Controllers\Api\UserController::class, 'index']);

    // Products
    Route::post('/uploads', [UploadController::class, 'store']);

    Route::apiResource('products', ProductController::class);
    Route::post('/products/import', [ProductController::class, 'import']);

    // Product categories
    Route::get('/product-categories', [ProductCategoryController::class, 'index']);

    // Product Settings (Units, Attributes, Features)
    Route::apiResource('product-units', ProductUnitController::class);
    Route::apiResource('product-attributes', ProductAttributeController::class);
    Route::apiResource('product-features', ProductFeatureController::class);
    Route::apiResource('product-custom-fields', ProductCustomFieldController::class);
    Route::post('/product-categories', [ProductCategoryController::class, 'store']);
    Route::put('/product-categories/{productCategory}', [ProductCategoryController::class, 'update']);
    Route::delete('/product-categories/{productCategory}', [ProductCategoryController::class, 'destroy']);

    // Product variants
    Route::get('/products/{product}/variants', [ProductVariantController::class, 'index']);
    Route::post('/products/{product}/variants', [ProductVariantController::class, 'store']);
    Route::put('/product-variants/{productVariant}', [ProductVariantController::class, 'update']);
    Route::delete('/product-variants/{productVariant}', [ProductVariantController::class, 'destroy']);

    // Customers
    Route::apiResource('customers', CustomerController::class);

    // Invoices
    Route::apiResource('invoices', InvoiceController::class);
    Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send']);

    // Payments
    Route::apiResource('payments', PaymentController::class);

    // Accounting
    Route::prefix('accounting')->group(function () {
        Route::get('/chart-of-accounts', [AccountingController::class, 'chartOfAccounts']);
        Route::get('/journal-entries', [AccountingController::class, 'journalEntries']);
        Route::get('/trial-balance', [AccountingController::class, 'trialBalance']);
        Route::get('/ledger/{account}', [AccountingController::class, 'ledger']);
    });
});
