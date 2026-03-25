<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AccountingController;
use App\Http\Controllers\Api\ChartOfAccountsController;
use App\Http\Controllers\Api\AgedReceivablesController;
use App\Http\Controllers\Api\AgedPayablesController;
use App\Http\Controllers\Api\BankReconciliationController;
use App\Http\Controllers\Api\JournalsController;
use App\Http\Controllers\Api\JournalEntriesController;
use App\Http\Controllers\Api\FixedAssetsController;
use App\Http\Controllers\Api\LockDatesController;
use App\Http\Controllers\Api\CurrenciesController;
use App\Http\Controllers\Api\ExchangeRatesController;
use App\Http\Controllers\Api\AnalyticAccountsController;
use App\Http\Controllers\Api\CompanySettingsController;
use App\Http\Controllers\Api\PaymentTermsController;
use App\Http\Controllers\Api\TaxRatesController;
use App\Http\Controllers\Api\BankAccountsController;
use App\Http\Controllers\Api\VendorBillsController;
use App\Http\Controllers\Api\BillPaymentsController;
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
use App\Http\Controllers\Api\CustomerCreditNotesController;
use App\Http\Controllers\Api\VendorCreditNotesController;
use App\Http\Controllers\Api\ARTransactionsController;
use App\Http\Controllers\Api\APTransactionsController;
use App\Http\Controllers\Api\CustomersController;
use App\Http\Controllers\Api\VendorsController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\AccountingDashboardController;

// Auth
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

// Import (requires auth)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/import', [ImportController::class, 'import']);
    Route::get('/import/template', [ImportController::class, 'downloadTemplate']);
});

// TEST ENDPOINT - NO AUTH REQUIRED
Route::get('/test-credit-notes', function() {
    $creditNotes = \Illuminate\Support\Facades\DB::table('customer_credit_notes')->limit(5)->get();
    return response()->json([
        'count' => $creditNotes->count(),
        'data' => $creditNotes
    ]);
});

// TEMPORARY: Credit notes and transactions without auth (for development only)
Route::prefix('accounting')->group(function () {
    Route::get('/customers/credit-notes', [CustomerCreditNotesController::class, 'index']);
    Route::get('/customers/credit-notes/{creditNote}', [CustomerCreditNotesController::class, 'show']);
    Route::get('/vendors/credit-notes', [VendorCreditNotesController::class, 'index']);
    Route::get('/vendors/credit-notes/{creditNote}', [VendorCreditNotesController::class, 'show']);
    
    // AR/AP Transactions
    Route::get('/customers/transactions', [ARTransactionsController::class, 'index']);
    Route::get('/customers/{customerId}/balance', [ARTransactionsController::class, 'getBalance']);
    Route::get('/customers/transactions/export', [ARTransactionsController::class, 'export']);
    Route::get('/vendors/transactions', [APTransactionsController::class, 'index']);
    Route::get('/vendors/{vendorId}/balance', [APTransactionsController::class, 'getBalance']);
    Route::get('/vendors/transactions/export', [APTransactionsController::class, 'export']);
    
    // Customers and Vendors lists
    Route::get('/customers', [CustomersController::class, 'index']);
    Route::get('/customers/{id}', [CustomersController::class, 'show']);
    Route::post('/customers/import', [CustomersController::class, 'import']);
    Route::get('/vendors', [VendorsController::class, 'index']);
    Route::get('/vendors/{id}', [VendorsController::class, 'show']);
    Route::post('/vendors/import', [VendorsController::class, 'import']);
    
    // Vendor Bills - MOVED TO AUTH SECTION BELOW
    
    // Aged Receivables and Payables Reports
    Route::get('/reports/aged-receivables', [AgedReceivablesController::class, 'index']);
    Route::get('/reports/aged-receivables/details', [AgedReceivablesController::class, 'details']);
    Route::get('/reports/aged-payables', [AgedPayablesController::class, 'index']);
    Route::get('/reports/aged-payables/details', [AgedPayablesController::class, 'details']);
});

// Protected routes (with tenant context)
Route::middleware(['auth:sanctum', \App\Http\Middleware\SetTenantFromUserPreference::class, \App\Http\Middleware\EnsureTenantSearchPath::class])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    // Settings
    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings', [SettingsController::class, 'update']);
    Route::post('/settings/test-smtp', [SettingsController::class, 'testSmtp']);

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

    Route::post('/products/import', [ProductController::class, 'import']);
    Route::post('/products/bulk-delete', [ProductController::class, 'bulkDestroy']);
    Route::delete('/products/delete-all', [ProductController::class, 'destroyAll']);
    Route::apiResource('products', ProductController::class);

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

    // Customer Categories (must be before customers resource to avoid route conflicts)
    Route::get('customer-categories', [CustomerController::class, 'categories']);
    Route::post('customer-categories', [CustomerController::class, 'storeCategory']);
    
    // Customers - static routes first (before apiResource)
    Route::get('customers/statistics', [CustomerController::class, 'statistics']);
    Route::get('customers/search', [CustomerController::class, 'search']);
    
    // Customers - resource routes
    Route::apiResource('customers', CustomerController::class);
    
    // Customers - dynamic routes with {customer} parameter (must be after apiResource)
    Route::get('customers/{customer}/statistics', [CustomerController::class, 'customerStatistics']);
    Route::get('customers/{customer}/activity', [CustomerController::class, 'activity']);
    Route::get('customers/{customer}/loyalty', [CustomerController::class, 'loyaltyHistory']);
    Route::post('customers/{customer}/loyalty/adjust', [CustomerController::class, 'adjustLoyalty']);
    Route::post('customers/{customer}/recalculate-stats', [CustomerController::class, 'recalculateStats']);
    Route::post('customers/{customer}/send-email', [CustomerController::class, 'sendEmail']);
    Route::post('customers/{customer}/addresses', [CustomerController::class, 'addAddress']);
    Route::put('customers/{customer}/addresses/{address}', [CustomerController::class, 'updateAddress']);
    Route::delete('customers/{customer}/addresses/{address}', [CustomerController::class, 'deleteAddress']);
    Route::post('customers/{customer}/contacts', [CustomerController::class, 'addContact']);
    Route::put('customers/{customer}/contacts/{contact}', [CustomerController::class, 'updateContact']);
    Route::delete('customers/{customer}/contacts/{contact}', [CustomerController::class, 'deleteContact']);

    // Invoices
    Route::apiResource('invoices', InvoiceController::class);
    Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send']);

    // Payments
    Route::apiResource('payments', PaymentController::class);

    // Accounting
    Route::prefix('accounting')->group(function () {
        // Dashboard
        Route::get('/dashboard', [AccountingDashboardController::class, 'index']);
        Route::get('/dashboard-test', function() {
            return response()->json(['test' => 'Dashboard route works!']);
        });
        
        // Chart of Accounts
        Route::get('/chart-of-accounts', [ChartOfAccountsController::class, 'index']);
        Route::get('/chart-of-accounts/hierarchy/tree', [ChartOfAccountsController::class, 'tree']);
        Route::get('/chart-of-accounts/{id}', [ChartOfAccountsController::class, 'show']);
        Route::post('/chart-of-accounts', [ChartOfAccountsController::class, 'store']);
        Route::put('/chart-of-accounts/{id}', [ChartOfAccountsController::class, 'update']);
        Route::delete('/chart-of-accounts/{id}', [ChartOfAccountsController::class, 'destroy']);
        
        // Journals Configuration
        Route::apiResource('journals', JournalsController::class);
        Route::get('/journals/{id}/next-entry-number', [JournalsController::class, 'getNextEntryNumber']);
        
        // Journal Entries
        Route::get('/journal-entries', [JournalEntriesController::class, 'index']);
        Route::get('/journal-entries/{id}', [JournalEntriesController::class, 'show']);
        Route::post('/journal-entries', [JournalEntriesController::class, 'store']);
        Route::put('/journal-entries/{id}', [JournalEntriesController::class, 'update']);
        Route::delete('/journal-entries/{id}', [JournalEntriesController::class, 'destroy']);
        Route::post('/journal-entries/{id}/post', [JournalEntriesController::class, 'post']);
        Route::post('/journal-entries/{id}/reverse', [JournalEntriesController::class, 'reverse']);
        Route::post('/journal-entries/validate-date', [JournalEntriesController::class, 'validateDate']);
        
        // Reports
        Route::get('/trial-balance', [AccountingController::class, 'trialBalance']);
        Route::get('/ledger/{account}', [AccountingController::class, 'ledger']);
        
        // Period-End Processes
        Route::post('/fx-revaluation', [AccountingController::class, 'fxRevaluation']);
        
        // Vendor Bills (with tenant context)
        Route::get('/vendor-bills', [VendorBillsController::class, 'index']);
        Route::get('/vendor-bills/{id}', [VendorBillsController::class, 'show']);
        Route::post('/vendor-bills', [VendorBillsController::class, 'store']);
        Route::put('/vendor-bills/{id}', [VendorBillsController::class, 'update']);
        Route::delete('/vendor-bills/{id}', [VendorBillsController::class, 'destroy']);
        Route::post('/vendor-bills/{id}/post', [VendorBillsController::class, 'post']);
        
        // Aged Receivables Report - moved outside auth temporarily
        // Route::get('/reports/aged-receivables', [AgedReceivablesController::class, 'index']);
        // Route::get('/reports/aged-receivables/details', [AgedReceivablesController::class, 'details']);
        
        // Aged Payables Report - moved outside auth temporarily
        // Route::get('/reports/aged-payables', [AgedPayablesController::class, 'index']);
        // Route::get('/reports/aged-payables/details', [AgedPayablesController::class, 'details']);
        
        // Bank Reconciliation
        Route::get('/reconciliation/bank-accounts', [BankReconciliationController::class, 'getBankAccounts']);
        Route::get('/reconciliation/transactions/{accountId}', [BankReconciliationController::class, 'getTransactions']);
        Route::post('/reconciliation/complete', [BankReconciliationController::class, 'complete']);
        Route::post('/reconciliation/add-transaction', [BankReconciliationController::class, 'addTransaction']);
        Route::get('/reconciliation/history/{accountId}', [BankReconciliationController::class, 'history']);
        
        // Fixed Assets
        Route::get('/assets', [FixedAssetsController::class, 'index']);
        Route::get('/assets/{id}', [FixedAssetsController::class, 'show']);
        Route::post('/assets', [FixedAssetsController::class, 'store']);
        Route::put('/assets/{id}', [FixedAssetsController::class, 'update']);
        Route::delete('/assets/{id}', [FixedAssetsController::class, 'destroy']);
        Route::get('/assets/{id}/depreciation', [FixedAssetsController::class, 'getDepreciationSchedule']);
        Route::post('/assets/{id}/dispose', [FixedAssetsController::class, 'dispose']);
        Route::post('/assets/calculate-depreciation', [FixedAssetsController::class, 'calculateDepreciation']);
        
        // Lock Dates
        Route::get('/lock-dates', [LockDatesController::class, 'index']);
        Route::get('/lock-dates/current', [LockDatesController::class, 'current']);
        Route::get('/lock-dates/statistics', [LockDatesController::class, 'statistics']);
        Route::get('/lock-dates/{id}', [LockDatesController::class, 'show']);
        Route::post('/lock-dates', [LockDatesController::class, 'store']);
        Route::put('/lock-dates/{id}', [LockDatesController::class, 'update']);
        Route::delete('/lock-dates/{id}', [LockDatesController::class, 'destroy']);
        Route::post('/lock-dates/validate', [LockDatesController::class, 'validateDate']);
        Route::post('/lock-dates/request-override', [LockDatesController::class, 'requestOverride']);
        Route::get('/lock-dates/overrides/history', [LockDatesController::class, 'overrides']);
        
        // Currencies
        Route::get('/currencies', [CurrenciesController::class, 'index']);
        Route::get('/currencies/active', [CurrenciesController::class, 'active']);
        Route::get('/currencies/{code}', [CurrenciesController::class, 'show']);
        Route::post('/currencies', [CurrenciesController::class, 'store']);
        Route::put('/currencies/{code}', [CurrenciesController::class, 'update']);
        Route::delete('/currencies/{code}', [CurrenciesController::class, 'destroy']);
        
        // Exchange Rates
        Route::get('/exchange-rates', [ExchangeRatesController::class, 'index']);
        Route::get('/exchange-rates/latest', [ExchangeRatesController::class, 'latest']);
        Route::get('/exchange-rates/history', [ExchangeRatesController::class, 'history']);
        Route::post('/exchange-rates/convert', [ExchangeRatesController::class, 'convert']);
        Route::post('/exchange-rates/sync', [ExchangeRatesController::class, 'sync']);
        Route::get('/exchange-rates/{id}', [ExchangeRatesController::class, 'show']);
        Route::post('/exchange-rates', [ExchangeRatesController::class, 'store']);
        Route::put('/exchange-rates/{id}', [ExchangeRatesController::class, 'update']);
        Route::delete('/exchange-rates/{id}', [ExchangeRatesController::class, 'destroy']);
        
        // Analytic Accounts
        Route::get('/analytic-accounts', [AnalyticAccountsController::class, 'index']);
        Route::get('/analytic-accounts/tree', [AnalyticAccountsController::class, 'tree']);
        Route::get('/analytic-accounts/report-by-dimension', [AnalyticAccountsController::class, 'reportByDimension']);
        Route::get('/analytic-accounts/{id}', [AnalyticAccountsController::class, 'show']);
        Route::post('/analytic-accounts', [AnalyticAccountsController::class, 'store']);
        Route::put('/analytic-accounts/{id}', [AnalyticAccountsController::class, 'update']);
        Route::delete('/analytic-accounts/{id}', [AnalyticAccountsController::class, 'destroy']);
        Route::get('/analytic-accounts/{id}/balance', [AnalyticAccountsController::class, 'balance']);
        Route::get('/analytic-accounts/{id}/profit-loss', [AnalyticAccountsController::class, 'profitLoss']);
        
        // Company Settings
        Route::get('/settings', [CompanySettingsController::class, 'index']);
        Route::put('/settings', [CompanySettingsController::class, 'update']);
        Route::post('/settings/logo', [CompanySettingsController::class, 'uploadLogo']);
        Route::delete('/settings/logo', [CompanySettingsController::class, 'deleteLogo']);
        Route::get('/settings/fiscal-year', [CompanySettingsController::class, 'fiscalYear']);
        Route::put('/settings/fiscal-year', [CompanySettingsController::class, 'updateFiscalYear']);
        Route::get('/settings/currencies', [CompanySettingsController::class, 'currencies']);
        Route::put('/settings/currencies', [CompanySettingsController::class, 'updateCurrencies']);
        Route::get('/settings/accounts', [CompanySettingsController::class, 'accounts']);
        Route::put('/settings/accounts', [CompanySettingsController::class, 'updateAccounts']);
        
        // Accounting Policies
        Route::get('/policies', [\App\Http\Controllers\Api\AccountingPoliciesController::class, 'index']);
        Route::put('/policies', [\App\Http\Controllers\Api\AccountingPoliciesController::class, 'update']);
        
        // Payment Terms
        Route::get('/payment-terms', [PaymentTermsController::class, 'index']);
        Route::get('/payment-terms/default', [PaymentTermsController::class, 'getDefault']);
        Route::post('/payment-terms/calculate-due-date', [PaymentTermsController::class, 'calculateDueDate']);
        Route::get('/payment-terms/{id}', [PaymentTermsController::class, 'show']);
        Route::post('/payment-terms', [PaymentTermsController::class, 'store']);
        Route::put('/payment-terms/{id}', [PaymentTermsController::class, 'update']);
        Route::delete('/payment-terms/{id}', [PaymentTermsController::class, 'destroy']);
        
        // Bank Accounts
        Route::get('/bank-accounts', [BankAccountsController::class, 'index']);
        Route::get('/bank-accounts/{id}', [BankAccountsController::class, 'show']);
        Route::post('/bank-accounts', [BankAccountsController::class, 'store']);
        Route::put('/bank-accounts/{id}', [BankAccountsController::class, 'update']);
        Route::delete('/bank-accounts/{id}', [BankAccountsController::class, 'destroy']);
        Route::get('/bank-accounts/{id}/balance', [BankAccountsController::class, 'getBalance']);
        Route::post('/payment-terms/{id}/set-default', [PaymentTermsController::class, 'setDefault']);

        // Bill Payments (Vendor Payments)
        Route::get('/bill-payments', [BillPaymentsController::class, 'index']);
        Route::get('/bill-payments/{id}', [BillPaymentsController::class, 'show']);
        Route::post('/bill-payments', [BillPaymentsController::class, 'store']);
        Route::put('/bill-payments/{id}', [BillPaymentsController::class, 'update']);
        Route::delete('/bill-payments/{id}', [BillPaymentsController::class, 'destroy']);

        // Tax Rates
        Route::get('/tax-rates', [TaxRatesController::class, 'index']);
        Route::get('/tax-rates/{id}', [TaxRatesController::class, 'show']);
        Route::post('/tax-rates', [TaxRatesController::class, 'store']);
        Route::put('/tax-rates/{id}', [TaxRatesController::class, 'update']);
        Route::delete('/tax-rates/{id}', [TaxRatesController::class, 'destroy']);
        Route::post('/tax-rates/calculate', [TaxRatesController::class, 'calculateTax']);
        
        // Customers List - moved outside auth temporarily
        // Route::get('/customers', [CustomersController::class, 'index']);
        // Route::get('/customers/{id}', [CustomersController::class, 'show']);
        
        // Customer Credit Notes - WRITE operations still require auth
        // GET operations moved outside auth middleware temporarily
        Route::post('/customers/credit-notes', [CustomerCreditNotesController::class, 'store']);
        Route::put('/customers/credit-notes/{creditNote}', [CustomerCreditNotesController::class, 'update']);
        Route::delete('/customers/credit-notes/{creditNote}', [CustomerCreditNotesController::class, 'destroy']);
        Route::post('/customers/credit-notes/{creditNote}/post', [CustomerCreditNotesController::class, 'post']);
        Route::get('/customers/invoices/{invoiceId}/credit-notes', [CustomerCreditNotesController::class, 'getByInvoice']);
        Route::post('/customers/credit-notes/validate', [CustomerCreditNotesController::class, 'validate']);
        
        // Vendors List - moved outside auth temporarily
        // Route::get('/vendors', [VendorsController::class, 'index']);
        // Route::get('/vendors/{id}', [VendorsController::class, 'show']);
        
        // Vendor Credit Notes - WRITE operations still require auth
        // GET operations moved outside auth middleware temporarily
        Route::post('/vendors/credit-notes', [VendorCreditNotesController::class, 'store']);
        Route::put('/vendors/credit-notes/{creditNote}', [VendorCreditNotesController::class, 'update']);
        Route::delete('/vendors/credit-notes/{creditNote}', [VendorCreditNotesController::class, 'destroy']);
        Route::post('/vendors/credit-notes/{creditNote}/post', [VendorCreditNotesController::class, 'post']);
        Route::get('/vendors/bills/{billId}/credit-notes', [VendorCreditNotesController::class, 'getByBill']);
        Route::post('/vendors/credit-notes/validate', [VendorCreditNotesController::class, 'validate']);
        
        // AR Transactions Ledger - moved outside auth temporarily
        // Route::get('/customers/transactions', [ARTransactionsController::class, 'index']);
        // Route::get('/customers/{customerId}/balance', [ARTransactionsController::class, 'getBalance']);
        // Route::get('/customers/transactions/export', [ARTransactionsController::class, 'export']);
        
        // AP Transactions Ledger - moved outside auth temporarily
        // Route::get('/vendors/transactions', [APTransactionsController::class, 'index']);
        // Route::get('/vendors/{vendorId}/balance', [APTransactionsController::class, 'getBalance']);
        // Route::get('/vendors/transactions/export', [APTransactionsController::class, 'export']);
    });
});
