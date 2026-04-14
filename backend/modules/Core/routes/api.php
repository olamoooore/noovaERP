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
use App\Http\Controllers\Api\CustomerStatementController;
use App\Http\Controllers\Api\CustomersController;
use App\Http\Controllers\Api\VendorsController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\AccountingDashboardController;
use App\Http\Controllers\Api\AccountRolesController;
use App\Http\Controllers\Api\OnboardingSessionsController;
use App\Http\Controllers\Api\EntityManagementController;
use App\Http\Controllers\Api\FiscalPeriodsController;
use App\Http\Middleware\ResolveEntityContext;
use App\Http\Middleware\EnsureEntityAccess;
use App\Http\Middleware\EnsureAccountingWriteAccess;

// Auth routes are registered centrally in routes/api.php (v1/auth/login, auth/login).
// Do NOT duplicate them here — this file is loaded under tenant middleware
// and would create an unauthenticated /api/v1/login surface.

// Import (requires auth + tenant + entity + accounting write)
Route::middleware([
    'auth:sanctum',
    \App\Http\Middleware\SetTenantFromUserPreference::class,
    \App\Http\Middleware\EnsureTenantSearchPath::class,
    \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
    ResolveEntityContext::class,
    EnsureEntityAccess::class,
    EnsureAccountingWriteAccess::class,
])->group(function () {
    Route::post('/import', [ImportController::class, 'import']);
    Route::get('/import/template', [ImportController::class, 'downloadTemplate']);
});

// All accounting routes require authentication — see EDR-ACC-ARCH-2026-03-29 §7

// Protected routes (with tenant context)
Route::middleware(['auth:sanctum', \App\Http\Middleware\SetTenantFromUserPreference::class, \App\Http\Middleware\EnsureTenantSearchPath::class, \App\Http\Middleware\SyncTenantUserFromCentralAuth::class])->group(function () {
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
    Route::put('/product-categories/{id}', [ProductCategoryController::class, 'update']);
    Route::delete('/product-categories/{id}', [ProductCategoryController::class, 'destroy']);

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

        Route::middleware([ResolveEntityContext::class, EnsureEntityAccess::class, EnsureAccountingWriteAccess::class])->group(function () {
            // Invoices
            Route::apiResource('invoices', InvoiceController::class);
            Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send']);

            // Payments (no update — PaymentController has no update method)
            Route::get('/payments', [PaymentController::class, 'index']);
            Route::post('/payments', [PaymentController::class, 'store']);
            Route::get('/payments/{payment}', [PaymentController::class, 'show']);
            Route::delete('/payments/{payment}', [PaymentController::class, 'destroy']);
        });

    // Accounting
    Route::prefix('accounting')->middleware([ResolveEntityContext::class, EnsureEntityAccess::class, EnsureAccountingWriteAccess::class])->group(function () {
        // Dashboard
        Route::get('/dashboard', [AccountingDashboardController::class, 'index']);

        
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
        
        // Aged Receivables Report
        Route::get('/reports/aged-receivables', [AgedReceivablesController::class, 'index']);
        Route::get('/reports/aged-receivables/details', [AgedReceivablesController::class, 'details']);
        
        // Aged Payables Report
        Route::get('/reports/aged-payables', [AgedPayablesController::class, 'index']);
        Route::get('/reports/aged-payables/details', [AgedPayablesController::class, 'details']);
        
        // Bank Reconciliation
        Route::get('/reconciliation/bank-accounts', [BankReconciliationController::class, 'getBankAccounts']);
        Route::get('/reconciliation/transactions/{bankAccountId}', [BankReconciliationController::class, 'getTransactions']);
        Route::post('/reconciliation/complete', [BankReconciliationController::class, 'complete']);
        Route::post('/reconciliation/add-transaction', [BankReconciliationController::class, 'addTransaction']);
        Route::get('/reconciliation/history/{bankAccountId}', [BankReconciliationController::class, 'history']);
        
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
        
        // Fiscal Periods — CRUD + lifecycle (lock / unlock / close / reopen)
        Route::get('/fiscal-periods', [FiscalPeriodsController::class, 'index']);
        Route::post('/fiscal-periods', [FiscalPeriodsController::class, 'store']);
        Route::post('/fiscal-periods/generate-year', [FiscalPeriodsController::class, 'generateYear']);
        Route::get('/fiscal-periods/{id}', [FiscalPeriodsController::class, 'show']);
        Route::put('/fiscal-periods/{id}', [FiscalPeriodsController::class, 'update']);
        Route::delete('/fiscal-periods/{id}', [FiscalPeriodsController::class, 'destroy']);
        Route::post('/fiscal-periods/{id}/lock', [FiscalPeriodsController::class, 'lock']);
        Route::post('/fiscal-periods/{id}/unlock', [FiscalPeriodsController::class, 'unlock']);
        Route::post('/fiscal-periods/{id}/close', [FiscalPeriodsController::class, 'close']);
        Route::post('/fiscal-periods/{id}/reopen', [FiscalPeriodsController::class, 'reopen']);

        // Account Roles (Phase 2 — role-based account resolution)
        Route::get('/account-roles', [AccountRolesController::class, 'index']);
        Route::post('/account-roles/assign', [AccountRolesController::class, 'assign']);
        Route::delete('/account-roles/{roleId}/unassign', [AccountRolesController::class, 'unassign']);

        // Onboarding (Phase 3 — onboarding engine)
        Route::get('/onboarding/steps', [OnboardingSessionsController::class, 'steps']);
        Route::get('/onboarding/sessions', [OnboardingSessionsController::class, 'index']);
        Route::post('/onboarding/sessions', [OnboardingSessionsController::class, 'store']);
        Route::get('/onboarding/sessions/{id}', [OnboardingSessionsController::class, 'show']);
        Route::post('/onboarding/sessions/{id}/advance', [OnboardingSessionsController::class, 'advance']);
        Route::post('/onboarding/sessions/{id}/resume', [OnboardingSessionsController::class, 'resume']);
        Route::post('/onboarding/sessions/{id}/abandon', [OnboardingSessionsController::class, 'abandon']);
        Route::post('/onboarding/sessions/{id}/skip', [OnboardingSessionsController::class, 'skip']);

        // Entity Management (Phase 4 — Multi-Entity)
        Route::get('/groups', [EntityManagementController::class, 'indexGroups']);
        Route::post('/groups', [EntityManagementController::class, 'storeGroup']);
        Route::get('/groups/{id}', [EntityManagementController::class, 'showGroup']);
        Route::put('/groups/{id}', [EntityManagementController::class, 'updateGroup']);

        Route::get('/legal-entities', [EntityManagementController::class, 'indexEntities']);
        Route::post('/legal-entities', [EntityManagementController::class, 'storeEntity']);
        Route::get('/legal-entities/{id}', [EntityManagementController::class, 'showEntity']);
        Route::put('/legal-entities/{id}', [EntityManagementController::class, 'updateEntity']);

        Route::get('/branches', [EntityManagementController::class, 'indexBranches']);
        Route::post('/branches', [EntityManagementController::class, 'storeBranch']);

        Route::get('/entity-books', [EntityManagementController::class, 'indexBooks']);
        Route::post('/entity-books', [EntityManagementController::class, 'storeBook']);

        Route::get('/legal-entities/{entityId}/modules', [EntityManagementController::class, 'indexModules']);
        Route::post('/legal-entities/{entityId}/modules/activate', [EntityManagementController::class, 'activateModule']);
        Route::post('/legal-entities/{entityId}/modules/deactivate', [EntityManagementController::class, 'deactivateModule']);
        Route::post('/legal-entities/{entityId}/modules/industry-pack', [EntityManagementController::class, 'applyIndustryPack']);

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
        // Logo upload/delete: routes removed until controller methods are implemented
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
        
        // Customers List
        Route::get('/customers', [CustomersController::class, 'index']);
        Route::get('/customers/{id}', [CustomersController::class, 'show']);
        Route::post('/customers/import', [CustomersController::class, 'import']);
        
        // Customer Credit Notes
        Route::get('/customers/credit-notes', [CustomerCreditNotesController::class, 'index']);
        Route::get('/customers/credit-notes/{creditNote}', [CustomerCreditNotesController::class, 'show']);
        Route::post('/customers/credit-notes', [CustomerCreditNotesController::class, 'store']);
        Route::put('/customers/credit-notes/{creditNote}', [CustomerCreditNotesController::class, 'update']);
        Route::delete('/customers/credit-notes/{creditNote}', [CustomerCreditNotesController::class, 'destroy']);
        Route::post('/customers/credit-notes/{creditNote}/post', [CustomerCreditNotesController::class, 'post']);
        Route::get('/customers/invoices/{invoiceId}/credit-notes', [CustomerCreditNotesController::class, 'getByInvoice']);
        Route::post('/customers/credit-notes/validate', [CustomerCreditNotesController::class, 'validate']);
        
        // Vendors List
        Route::get('/vendors', [VendorsController::class, 'index']);
        Route::get('/vendors/{id}', [VendorsController::class, 'show']);
        Route::post('/vendors', [VendorsController::class, 'store']);
        Route::put('/vendors/{id}', [VendorsController::class, 'update']);
        Route::post('/vendors/import', [VendorsController::class, 'import']);
        
        // Vendor Credit Notes
        Route::get('/vendors/credit-notes', [VendorCreditNotesController::class, 'index']);
        Route::get('/vendors/credit-notes/{creditNote}', [VendorCreditNotesController::class, 'show']);
        Route::post('/vendors/credit-notes', [VendorCreditNotesController::class, 'store']);
        Route::put('/vendors/credit-notes/{creditNote}', [VendorCreditNotesController::class, 'update']);
        Route::delete('/vendors/credit-notes/{creditNote}', [VendorCreditNotesController::class, 'destroy']);
        Route::post('/vendors/credit-notes/{creditNote}/post', [VendorCreditNotesController::class, 'post']);
        Route::get('/vendors/bills/{billId}/credit-notes', [VendorCreditNotesController::class, 'getByBill']);
        Route::post('/vendors/credit-notes/validate', [VendorCreditNotesController::class, 'validate']);
        
        // AR Transactions Ledger
        Route::get('/customers/transactions', [ARTransactionsController::class, 'index']);
        Route::get('/customers/{customerId}/balance', [ARTransactionsController::class, 'getBalance']);
        Route::get('/customers/transactions/export', [ARTransactionsController::class, 'export']);
        
        // AP Transactions Ledger
        Route::get('/vendors/transactions', [APTransactionsController::class, 'index']);
        Route::get('/vendors/{vendorId}/balance', [APTransactionsController::class, 'getBalance']);
        Route::get('/vendors/transactions/export', [APTransactionsController::class, 'export']);

        // Customer Statements
        Route::post('/customers/{customer}/statement', [CustomerStatementController::class, 'generate']);
        Route::get('/customers/statements/history', [CustomerStatementController::class, 'history']);
        Route::get('/customers/statements/{statement}', [CustomerStatementController::class, 'show']);
    });
});
