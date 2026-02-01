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
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\ProductBundleController;
use App\Http\Controllers\Api\ProductTrackingController;
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
    Route::get('/products-statistics', [ProductController::class, 'statistics']);
    Route::get('/products-reorder-report', [ProductController::class, 'reorderReport']);
    Route::get('/products/{product}/audit-log', [ProductController::class, 'auditLog']);
    Route::post('/products/{product}/submit-for-approval', [ProductController::class, 'submitForApproval']);
    Route::post('/products/{product}/approve', [ProductController::class, 'approve']);
    Route::post('/products/{product}/reject', [ProductController::class, 'reject']);
    Route::post('/products/{product}/discontinue', [ProductController::class, 'discontinue']);
    Route::post('/products/{product}/archive', [ProductController::class, 'archive']);
    Route::post('/products/{product}/reactivate', [ProductController::class, 'reactivate']);
    Route::post('/products/{product}/calculate-price', [ProductController::class, 'calculatePrice']);
    Route::post('/products/{product}/duplicate', [ProductController::class, 'duplicate']);

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

    // Suppliers
    Route::apiResource('suppliers', SupplierController::class);
    Route::get('/suppliers/{supplier}/products', [SupplierController::class, 'products']);
    Route::post('/suppliers/{supplier}/products', [SupplierController::class, 'linkProduct']);
    Route::put('/suppliers/{supplier}/products/{product}', [SupplierController::class, 'updateProductLink']);
    Route::delete('/suppliers/{supplier}/products/{product}', [SupplierController::class, 'unlinkProduct']);
    Route::get('/suppliers-statistics', [SupplierController::class, 'statistics']);

    // Product Bundles
    Route::get('/bundles', [ProductBundleController::class, 'index']);
    Route::get('/products/{product}/bundle-components', [ProductBundleController::class, 'components']);
    Route::post('/products/{product}/bundle-components', [ProductBundleController::class, 'addComponent']);
    Route::put('/products/{bundle}/bundle-components/{component}', [ProductBundleController::class, 'updateComponent']);
    Route::delete('/products/{bundle}/bundle-components/{component}', [ProductBundleController::class, 'removeComponent']);
    Route::post('/products/{bundle}/bundle-components/reorder', [ProductBundleController::class, 'reorderComponents']);
    Route::get('/products/{bundle}/bundle-availability', [ProductBundleController::class, 'checkAvailability']);
    Route::post('/products/{product}/convert-to-bundle', [ProductBundleController::class, 'convertToBundle']);
    Route::delete('/products/{product}/remove-bundle', [ProductBundleController::class, 'removeBundle']);

    // Product Batch Tracking
    Route::get('/products/{product}/batches', [ProductTrackingController::class, 'batches']);
    Route::post('/products/{product}/batches', [ProductTrackingController::class, 'createBatch']);
    Route::put('/products/{product}/batches/{batch}', [ProductTrackingController::class, 'updateBatch']);
    Route::post('/products/{product}/batches/{batch}/adjust', [ProductTrackingController::class, 'adjustBatch']);
    Route::post('/products/{product}/batches/{batch}/reserve', [ProductTrackingController::class, 'reserveBatch']);
    Route::post('/products/{product}/batches/{batch}/release', [ProductTrackingController::class, 'releaseBatch']);
    Route::get('/batches/expiring', [ProductTrackingController::class, 'expiringBatches']);
    Route::get('/batches/expired', [ProductTrackingController::class, 'expiredBatches']);

    // Product Serial Number Tracking
    Route::get('/products/{product}/serials', [ProductTrackingController::class, 'serials']);
    Route::post('/products/{product}/serials', [ProductTrackingController::class, 'createSerial']);
    Route::post('/products/{product}/serials/bulk', [ProductTrackingController::class, 'createSerialsBulk']);
    Route::put('/products/{product}/serials/{serial}', [ProductTrackingController::class, 'updateSerial']);
    Route::post('/products/{product}/serials/{serial}/sell', [ProductTrackingController::class, 'markSerialSold']);
    Route::post('/products/{product}/serials/{serial}/return', [ProductTrackingController::class, 'markSerialReturned']);
    Route::get('/serials/find', [ProductTrackingController::class, 'findSerial']);
    Route::get('/serials/{serial}/warranty', [ProductTrackingController::class, 'warrantyStatus']);

    // Customers - Enhanced with res.partner features
    Route::apiResource('customers', CustomerController::class);
    Route::get('/customers-search', [CustomerController::class, 'search']);
    Route::get('/customers-statistics', [CustomerController::class, 'statistics']);
    Route::get('/customer-categories', [CustomerController::class, 'categories']);
    Route::post('/customer-categories', [CustomerController::class, 'storeCategory']);
    
    // Customer Addresses
    Route::post('/customers/{customer}/addresses', [CustomerController::class, 'addAddress']);
    Route::put('/customers/{customer}/addresses/{address}', [CustomerController::class, 'updateAddress']);
    Route::delete('/customers/{customer}/addresses/{address}', [CustomerController::class, 'deleteAddress']);
    
    // Customer Contacts
    Route::post('/customers/{customer}/contacts', [CustomerController::class, 'addContact']);
    Route::put('/customers/{customer}/contacts/{contact}', [CustomerController::class, 'updateContact']);
    Route::delete('/customers/{customer}/contacts/{contact}', [CustomerController::class, 'deleteContact']);
    
    // Customer Loyalty
    Route::get('/customers/{customer}/loyalty', [CustomerController::class, 'loyaltyHistory']);
    Route::post('/customers/{customer}/loyalty/adjust', [CustomerController::class, 'adjustLoyalty']);
    
    // Customer Activity & Statistics
    Route::get('/customers/{customer}/activity', [CustomerController::class, 'activity']);
    Route::get('/customers/{customer}/statistics', [CustomerController::class, 'customerStatistics']);
    Route::post('/customers/{customer}/recalculate-stats', [CustomerController::class, 'recalculateStats']);
    Route::post('/customers/{customer}/send-email', [CustomerController::class, 'sendEmail']);

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
