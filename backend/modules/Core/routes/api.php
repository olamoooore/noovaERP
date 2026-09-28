<?php

use App\Http\Controllers\Api\AccountingController;
use App\Http\Controllers\Api\AccountingDashboardController;
use App\Http\Controllers\Api\AccountingOriginController;
use App\Http\Controllers\Api\AccountRolesController;
use App\Http\Controllers\Api\AgedPayablesController;
use App\Http\Controllers\Api\AgedReceivablesController;
use App\Http\Controllers\Api\AnalyticAccountsController;
use App\Http\Controllers\Api\APTransactionsController;
use App\Http\Controllers\Api\ARTransactionsController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\AutoMatchController;
use App\Http\Controllers\Api\BankAccountsController;
use App\Http\Controllers\Api\BankReconciliationController;
use App\Http\Controllers\Api\BankStatementAdjustmentController;
use App\Http\Controllers\Api\BankStatementImportController;
use App\Http\Controllers\Api\BankStatementMatchController;
use App\Http\Controllers\Api\BankStatementReconciliationController;
use App\Http\Controllers\Api\BankStatementTransactionController;
use App\Http\Controllers\Api\BillPaymentsController;
use App\Http\Controllers\Api\BonusSchemeController;
use App\Http\Controllers\Api\ChartOfAccountsController;
use App\Http\Controllers\Api\CompanySettingsController;
use App\Http\Controllers\Api\CurrenciesController;
use App\Http\Controllers\Api\CustomerBonusSchemeController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerCreditNotesController;
use App\Http\Controllers\Api\CustomersController;
use App\Http\Controllers\Api\CustomerStatementController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EntityManagementController;
use App\Http\Controllers\Api\ExchangeRatesController;
use App\Http\Controllers\Api\FeatureController;
use App\Http\Controllers\Api\FiscalPeriodsController;
use App\Http\Controllers\Api\FixedAssetsController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\JournalEntriesController;
use App\Http\Controllers\Api\JournalsController;
use App\Http\Controllers\Api\LockDatesController;
use App\Http\Controllers\Api\MedicalCaseController;
use App\Http\Controllers\Api\OnboardingSessionsController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentImportController;
use App\Http\Controllers\Api\PaymentTermsController;
use App\Http\Controllers\Api\ProductAttributeController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductCustomFieldController;
use App\Http\Controllers\Api\ProductFeatureController;
use App\Http\Controllers\Api\ProductTrackingController;
use App\Http\Controllers\Api\ProductUnitController;
use App\Http\Controllers\Api\ProductVariantController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\ReconciliationExceptionController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SubledgerReconciliationController;
use App\Http\Controllers\Api\TaxController;
use App\Http\Controllers\Api\TaxRatesController;
use App\Http\Controllers\Api\VendorBillsController;
use App\Http\Controllers\Api\VendorCreditNotesController;
use App\Http\Controllers\Api\VendorDebitNotesController;
use App\Http\Controllers\Api\VendorsController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Middleware\EnsureAccountingLockManageAccess;
use App\Http\Middleware\EnsureAccountingPostAccess;
use App\Http\Middleware\EnsureAccountingReverseAccess;
use App\Http\Middleware\EnsureAccountingWriteAccess;
use App\Http\Middleware\EnsureEntityAccess;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveEntityContext;
use Illuminate\Support\Facades\Route;

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
// Issue #173 (CRITICAL): ImpersonationMiddleware runs after auth:sanctum so a
// SuperAdmin's X-Impersonation-Token swaps the authenticated user to the target
// tenant user (and initializes their tenancy) before the tenant-resolution
// middleware below. Without this the impersonation feature was a silent no-op.
Route::middleware(['auth:sanctum', \App\Http\Middleware\ImpersonationMiddleware::class, \App\Http\Middleware\SetTenantFromUserPreference::class, \App\Http\Middleware\EnsureTenantSearchPath::class, \App\Http\Middleware\SyncTenantUserFromCentralAuth::class])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/products/{product}/tracking-availability', [ProductTrackingController::class, 'availability']);

    // Settings — locked to OWNER/ADMIN via settings.view/settings.write.
    // SUPERADMIN bypasses via RequirePermission's is_super_admin shortcut.
    Route::get('/settings', [SettingsController::class, 'show'])
        ->middleware(RequirePermission::using('settings.view'));
    Route::put('/settings', [SettingsController::class, 'update'])
        ->middleware(RequirePermission::using('settings.write'));
    Route::post('/settings/test-smtp', [SettingsController::class, 'testSmtp'])
        ->middleware(RequirePermission::using('settings.write'));

    // Sales Settings (tenant-root, no accounting prefix) — used by the Sales Settings UI
    Route::get('/settings/document-formats', [CompanySettingsController::class, 'documentFormats'])
        ->middleware(RequirePermission::using('settings.view'));
    Route::put('/settings/document-formats', [CompanySettingsController::class, 'updateDocumentFormats'])
        ->middleware(RequirePermission::using('settings.write'));
    Route::get('/settings/sales', [CompanySettingsController::class, 'getSalesSettings'])
        ->middleware(RequirePermission::using('settings.view'));
    Route::put('/settings/sales', [CompanySettingsController::class, 'updateSalesSettings'])
        ->middleware(RequirePermission::using('settings.write'));

    // Taxes
    Route::get('/taxes', [TaxController::class, 'index']);
    Route::post('/taxes', [TaxController::class, 'store']);
    Route::put('/taxes/{tax}', [TaxController::class, 'update']);
    Route::delete('/taxes/{tax}', [TaxController::class, 'destroy']);

    // Features
    Route::get('/features', [FeatureController::class, 'index']);

    // Platform Modules (discovery + dependency-aware activation)
    Route::get('/modules', [App\Http\Controllers\Api\PlatformModuleController::class, 'index']);
    Route::get('/modules/dependencies/validate', [App\Http\Controllers\Api\PlatformModuleController::class, 'validateDependencies']);
    Route::get('/modules/{key}', [App\Http\Controllers\Api\PlatformModuleController::class, 'show'])->where('key', '[a-z][a-z0-9._-]+');
    Route::post('/modules/{key}/activate', [App\Http\Controllers\Api\PlatformModuleController::class, 'activate'])->where('key', '[a-z][a-z0-9._-]+');
    Route::post('/modules/{key}/deactivate', [App\Http\Controllers\Api\PlatformModuleController::class, 'deactivate'])->where('key', '[a-z][a-z0-9._-]+');

    // Dashboard (issue #113: resolve entity context so the controller can scope
    // its financial aggregates to the user's accessible legal entities).
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware([ResolveEntityContext::class, EnsureEntityAccess::class]);

    // Vendor Credit Notes

    // Products — issue #128 (CRITICAL): every product route now carries an
    // explicit RequirePermission gate (was: only auth:sanctum + tenant resolve,
    // i.e. ANY authenticated user could read/write/export/import/delete the
    // whole catalogue). products.* permissions mirror inventory.* role mappings
    // so no legitimate user loses access.
    Route::get('/products/export', [ProductController::class, 'export'])
        ->middleware(\App\Http\Middleware\RequirePermission::using('products.export'));
    Route::post('/products/import', [ProductController::class, 'import'])
        ->middleware(\App\Http\Middleware\RequirePermission::using('products.import'));
    Route::post('/products/bulk-delete', [ProductController::class, 'bulkDestroy'])
        ->middleware(\App\Http\Middleware\RequirePermission::using('products.delete'));
    // Issue #127 (BLOCKER): destructive bulk purge guarded by the strictest
    // permission (Owner/Admin only). The controller re-checks as defence in depth.
    Route::delete('/products/delete-all', [ProductController::class, 'destroyAll'])
        ->middleware(\App\Http\Middleware\RequirePermission::using('products.delete_all'));
    // Explicit verb routes (replaces apiResource) so each verb maps to the
    // correct granular permission.
    Route::get('/products', [ProductController::class, 'index'])
        ->middleware(\App\Http\Middleware\RequirePermission::using('products.view'));
    Route::post('/products', [ProductController::class, 'store'])
        ->middleware(\App\Http\Middleware\RequirePermission::using('products.create'));
    Route::get('/products/{product}', [ProductController::class, 'show'])
        ->middleware(\App\Http\Middleware\RequirePermission::using('products.view'));
    Route::match(['put', 'patch'], '/products/{product}', [ProductController::class, 'update'])
        ->middleware(\App\Http\Middleware\RequirePermission::using('products.edit'));
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])
        ->middleware(\App\Http\Middleware\RequirePermission::using('products.delete'));

    // Warehouses
    Route::apiResource('warehouses', WarehouseController::class);

    // Product categories — issue #128: read requires products.view, writes use
    // the same granular products.* convention as the product verbs above.
    // (Issue #133: store/update/destroy existed on the controller but had no
    // routes, so the Category Manager UI 404'd on create/edit/delete.)
    Route::get('/product-categories', [ProductCategoryController::class, 'index'])
        ->middleware(\App\Http\Middleware\RequirePermission::using('products.view'));
    Route::post('/product-categories', [ProductCategoryController::class, 'store'])
        ->middleware(\App\Http\Middleware\RequirePermission::using('products.create'));
    Route::match(['put', 'patch'], '/product-categories/{id}', [ProductCategoryController::class, 'update'])
        ->middleware(\App\Http\Middleware\RequirePermission::using('products.edit'));
    Route::delete('/product-categories/{id}', [ProductCategoryController::class, 'destroy'])
        ->middleware(\App\Http\Middleware\RequirePermission::using('products.delete'));

    // Vendor AP Transactions
    Route::get('/vendors/transactions', [APTransactionsController::class, 'index']);
    Route::get('/vendors/transactions/export', [APTransactionsController::class, 'export']);

    // Product Settings (Units, Attributes, Features, Custom Fields) — issue #128:
    // reads require products.view, writes require products.edit. Split via
    // ->only() so a Viewer/Accountant can still read config when viewing a
    // product, but only write-capable roles can mutate it.
    foreach ([
        'product-units' => ProductUnitController::class,
        'product-attributes' => ProductAttributeController::class,
        'product-features' => ProductFeatureController::class,
        'product-custom-fields' => ProductCustomFieldController::class,
    ] as $settingsUri => $settingsController) {
        Route::apiResource($settingsUri, $settingsController)->only(['index', 'show'])
            ->middleware(\App\Http\Middleware\RequirePermission::using('products.view'));
        Route::apiResource($settingsUri, $settingsController)->only(['store', 'update', 'destroy'])
            ->middleware(\App\Http\Middleware\RequirePermission::using('products.edit'));
    }
    // Vendors
    Route::get('/vendors', [VendorsController::class, 'index']);
    Route::post('/vendors', [VendorsController::class, 'store']);
    Route::post('/vendors/import', [VendorsController::class, 'import']);
    Route::get('/vendors/{id}', [VendorsController::class, 'show']);
    Route::put('/vendors/{id}', [VendorsController::class, 'update']);
    Route::delete('/vendors/{id}', [VendorsController::class, 'destroy']);
    Route::get('/vendors/{vendorId}/balance', [APTransactionsController::class, 'getBalance']);
    // Product variants — issue #128: guard with the same products.* granularity.
    Route::get('/products/{product}/variants', [ProductVariantController::class, 'index'])
        ->middleware(\App\Http\Middleware\RequirePermission::using('products.view'));
    Route::post('/products/{product}/variants', [ProductVariantController::class, 'store'])
        ->middleware(\App\Http\Middleware\RequirePermission::using('products.create'));
    Route::put('/product-variants/{productVariant}', [ProductVariantController::class, 'update'])
        ->middleware(\App\Http\Middleware\RequirePermission::using('products.edit'));
    Route::delete('/product-variants/{productVariant}', [ProductVariantController::class, 'destroy'])
        ->middleware(\App\Http\Middleware\RequirePermission::using('products.delete'));

    // Customer Categories (must be before customers resource to avoid route conflicts)
    // Issue #125 (HIGH): guard with customers.* — was previously ungated.
    Route::get('customer-categories', [CustomerController::class, 'categories'])
        ->middleware(RequirePermission::using('customers.view'));
    Route::post('customer-categories', [CustomerController::class, 'storeCategory'])
        ->middleware(RequirePermission::using('customers.write'));

    // Customers - static routes first (before apiResource)
    // Issue #125 (HIGH): base customer CRUD + static routes were ungated while
    // the contacts sub-resource on the same controller was protected. Now every
    // route carries customers.view (read) / customers.write (write) /
    // customers.delete (destroy) consistently.
    Route::get('customers/statistics', [CustomerController::class, 'statistics'])
        ->middleware(RequirePermission::using('customers.view'));
    Route::get('customers/search', [CustomerController::class, 'search'])
        ->middleware(RequirePermission::using('customers.view'));
    Route::get('customers/export', [CustomerController::class, 'export'])
        ->middleware(RequirePermission::using('customers.view'));
    Route::get('customers/{customer}/invoices', [CustomerController::class, 'invoices'])
        ->middleware(RequirePermission::using('customers.view'));
    Route::post('customers/bulk-delete', [CustomerController::class, 'bulkDestroy'])
        ->middleware(RequirePermission::using('customers.delete'));

    // Customers - resource routes (explicit verbs so each maps to the correct
    // granular permission; replaces the ungated apiResource — issue #125).
    Route::get('customers', [CustomerController::class, 'index'])
        ->middleware(RequirePermission::using('customers.view'));
    Route::post('customers', [CustomerController::class, 'store'])
        ->middleware(RequirePermission::using('customers.write'));
    Route::get('customers/{customer}', [CustomerController::class, 'show'])
        ->middleware(RequirePermission::using('customers.view'));
    Route::match(['put', 'patch'], 'customers/{customer}', [CustomerController::class, 'update'])
        ->middleware(RequirePermission::using('customers.write'));
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])
        ->middleware(RequirePermission::using('customers.delete'));

    // Customers - dynamic routes with {customer} parameter (must be after the
    // explicit resource routes). Issue #125: reads -> customers.view,
    // writes -> customers.write.
    Route::get('customers/{customer}/statistics', [CustomerController::class, 'customerStatistics'])
        ->middleware(RequirePermission::using('customers.view'));
    Route::get('customers/{customer}/activity', [CustomerController::class, 'activity'])
        ->middleware(RequirePermission::using('customers.view'));
    Route::get('customers/{customer}/loyalty', [CustomerController::class, 'loyaltyHistory'])
        ->middleware(RequirePermission::using('customers.view'));
    Route::post('customers/{customer}/loyalty/adjust', [CustomerController::class, 'adjustLoyalty'])
        ->middleware(RequirePermission::using('customers.write'));
    Route::post('customers/{customer}/recalculate-stats', [CustomerController::class, 'recalculateStats'])
        ->middleware(RequirePermission::using('customers.write'));
    Route::post('customers/{customer}/send-email', [CustomerController::class, 'sendEmail'])
        ->middleware(RequirePermission::using('customers.write'));
    Route::post('customers/{customer}/addresses', [CustomerController::class, 'addAddress'])
        ->middleware(RequirePermission::using('customers.write'));
    Route::put('customers/{customer}/addresses/{address}', [CustomerController::class, 'updateAddress'])
        ->middleware(RequirePermission::using('customers.write'));
    Route::delete('customers/{customer}/addresses/{address}', [CustomerController::class, 'deleteAddress'])
        ->middleware(RequirePermission::using('customers.write'));
    Route::post('customers/{customer}/contacts', [CustomerController::class, 'addContact'])
        ->middleware(RequirePermission::using('customers.write'));
    Route::put('customers/{customer}/contacts/{contact}', [CustomerController::class, 'updateContact'])
        ->middleware(RequirePermission::using('customers.write'));
    Route::delete('customers/{customer}/contacts/{contact}', [CustomerController::class, 'deleteContact'])
        ->middleware(RequirePermission::using('customers.write'));
    // ADR-003A CT-VEN-028: atomic SetPrimaryContact.
    Route::post('customers/{customer}/contacts/{contact}/set-primary', [CustomerController::class, 'setPrimaryContact'])
        ->middleware(RequirePermission::using('customers.write'));

    Route::middleware([ResolveEntityContext::class, EnsureEntityAccess::class, EnsureAccountingWriteAccess::class])->group(function () {
        // Invoices
        Route::post('/invoices/import', [InvoiceController::class, 'import'])
            ->middleware(RequirePermission::using('invoices.write'));
        Route::get('/invoices/export', [InvoiceController::class, 'export'])
            ->middleware(RequirePermission::using('invoices.view'));
        Route::post('/invoices/bulk-delete', [InvoiceController::class, 'bulkDestroy'])
            ->middleware(RequirePermission::using('invoices.write'));
        Route::post('/invoices/bulk-post', [InvoiceController::class, 'bulkPost'])
            ->middleware(RequirePermission::using('accounting.post'));

        // Invoice CRUD — explicit so per-method middleware is precise
        Route::get('/invoices', [InvoiceController::class, 'index'])
            ->middleware(RequirePermission::using('invoices.view'));
        Route::post('/invoices', [InvoiceController::class, 'store'])
            ->middleware(RequirePermission::using('invoices.write'));
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])
            ->middleware(RequirePermission::using('invoices.view'));
        Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])
            ->middleware(RequirePermission::using('invoices.write'));
        Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])
            ->middleware(RequirePermission::using('invoices.write'));

        // Approval flow
        Route::post('/invoices/{invoice}/submit-approval', [InvoiceController::class, 'submitApproval'])
            ->middleware(RequirePermission::using('invoices.submit'));
        Route::post('/invoices/{invoice}/withdraw-approval', [InvoiceController::class, 'withdrawApproval'])
            ->middleware(RequirePermission::using('invoices.write'));

        // Invoice → Delivery Order conversion.
        Route::post('/invoices/{invoice}/create-delivery-order', [InvoiceController::class, 'createDeliveryOrder'])
            ->middleware(RequirePermission::using('invoices.write'));

        // Delivery (Phase B — delivery without GL posting)
        Route::post('/invoices/{invoice}/deliver', [InvoiceController::class, 'deliver'])
            ->middleware(RequirePermission::using('invoices.send'));

        // Legacy /send — despite the name it posts to the GL (InvoiceController::send()
        // calls InvoiceService::postInvoice). Kept for backward compatibility, so it
        // must require the same right as /post: posting is accounting-only and is
        // never a sales action.
        Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send'])
            ->middleware(RequirePermission::using('accounting.post'));

        // GL Posting (Phase B — GL posting without delivery, separate endpoint)
        Route::post('/invoices/{invoice}/post', [InvoiceController::class, 'post'])
            ->middleware(RequirePermission::using('accounting.post'));

        // Evidence
        Route::post('/invoices/{invoice}/evidence', [InvoiceController::class, 'uploadEvidence'])
            ->middleware(RequirePermission::using('invoices.evidence.manage'));

        // Vendor confirmation of customer-uploaded evidence
        Route::post('/invoices/{invoice}/evidence/{evidence}/confirm', [InvoiceController::class, 'confirmEvidence'])
            ->middleware(RequirePermission::using('invoices.evidence.manage'));

        // View/download a piece of evidence
        Route::get('/invoices/{invoice}/evidence/{evidence}/download', [InvoiceController::class, 'downloadEvidence'])
            ->middleware(RequirePermission::using('invoices.view'));

        // Acceptance link (public portal link for customer acceptance)
        Route::post('/invoices/{invoice}/acceptance-link', [InvoiceController::class, 'createAcceptanceLink'])
            ->middleware(RequirePermission::using('invoices.evidence.manage'));

        // E-sign envelope (Phase E — external provider abstraction)
        Route::post('/invoices/{invoice}/send-for-esign', [InvoiceController::class, 'sendForEsign'])
            ->middleware(RequirePermission::using('invoices.evidence.manage'));

        // Payment link
        Route::post('/invoices/{invoice}/payment-link', [InvoiceController::class, 'createPaymentLink'])
            ->middleware(RequirePermission::using('invoices.request_payment'));

        // Timeline
        Route::get('/invoices/{invoice}/timeline', [InvoiceController::class, 'getTimeline'])
            ->middleware(RequirePermission::using('invoices.view'));

        Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])
            ->middleware(RequirePermission::using('invoices.write'));
        // Reverses the ledger and restores stock — admin/owner only.
        Route::post('/invoices/{invoice}/reset-to-draft', [InvoiceController::class, 'resetToDraft'])
            ->middleware(RequirePermission::using('documents.reset_to_draft'));

        // Issue #92: Budget Management (real budgets; the dashboard no longer
        // fabricates budget as 110% of actual).
        Route::middleware(RequirePermission::using('accounting.view'))->group(function () {
            Route::get('/accounting/budgets', [\App\Http\Controllers\Api\BudgetController::class, 'index']);
            Route::get('/accounting/budgets/{budget}', [\App\Http\Controllers\Api\BudgetController::class, 'show']);
            Route::get('/accounting/budgets/{budget}/variance', [\App\Http\Controllers\Api\BudgetController::class, 'variance']);
        });
        Route::middleware(RequirePermission::using('accounting.write'))->group(function () {
            Route::post('/accounting/budgets', [\App\Http\Controllers\Api\BudgetController::class, 'store']);
            Route::put('/accounting/budgets/{budget}', [\App\Http\Controllers\Api\BudgetController::class, 'update']);
            Route::delete('/accounting/budgets/{budget}', [\App\Http\Controllers\Api\BudgetController::class, 'destroy']);
            Route::post('/accounting/budgets/{budget}/approve', [\App\Http\Controllers\Api\BudgetController::class, 'approve']);
            Route::post('/accounting/budgets/{budget}/archive', [\App\Http\Controllers\Api\BudgetController::class, 'archive']);
        });

        // Quotations
        Route::post('/quotations/import', [QuotationController::class, 'import']);
        Route::get('/quotations/export', [QuotationController::class, 'export']);
        Route::post('/quotations/bulk-delete', [QuotationController::class, 'bulkDestroy']);
        Route::apiResource('quotations', QuotationController::class);
        Route::post('/quotations/{id}/confirm', [QuotationController::class, 'confirm']);
        Route::post('/quotations/{id}/cancel', [QuotationController::class, 'cancel']);
        // Issue #178/#179: rejection with auto-versioned replacement draft.
        Route::post('/quotations/{id}/reject', [QuotationController::class, 'reject']);
        Route::post('/quotations/{id}/reset-to-draft', [QuotationController::class, 'resetToDraft'])
            ->middleware(RequirePermission::using('documents.reset_to_draft'));
        Route::post('/quotations/{id}/create-invoice', [QuotationController::class, 'createInvoice']);
        Route::post('/quotations/{id}/create-delivery-order', [QuotationController::class, 'createDeliveryOrder'])
            ->middleware(RequirePermission::using('sales.write'));

        // Payments
        Route::get('/payments', [PaymentController::class, 'index']);
        Route::get('/payments/export', [PaymentController::class, 'export']);
        Route::get('/payments/eligible-invoices', [PaymentController::class, 'eligibleInvoices']);
        Route::post('/payments/import', [PaymentImportController::class, 'import']);
        Route::post('/payments/bulk-delete', [PaymentController::class, 'bulkDestroy']);
        Route::post('/payments/bulk-post', [PaymentController::class, 'bulkPost']);
        Route::post('/payments', [PaymentController::class, 'store']);
        Route::get('/payments/{payment}', [PaymentController::class, 'show']);
        Route::put('/payments/{payment}', [PaymentController::class, 'update']);
        Route::delete('/payments/{payment}', [PaymentController::class, 'destroy']);
        Route::post('/payments/{payment}/cancel', [PaymentController::class, 'cancel']);
        Route::post('/payments/{payment}/post', [PaymentController::class, 'post']);
        // Issue #155: split payments / unapplied credit.
        Route::get('/payments/{payment}/allocations', [PaymentController::class, 'allocations']);
        Route::post('/payments/{payment}/allocations', [PaymentController::class, 'allocate']);
    });

    // Accounting
    Route::prefix('accounting')->middleware([ResolveEntityContext::class, EnsureEntityAccess::class, EnsureAccountingWriteAccess::class])->group(function () {
        // Dashboard
        Route::get('/dashboard', [AccountingDashboardController::class, 'index']);

        // Chart of Accounts
        Route::get('/chart-of-accounts', [ChartOfAccountsController::class, 'index']);
        Route::get('/chart-of-accounts/export', [ChartOfAccountsController::class, 'export']);
        Route::get('/chart-of-accounts/import-template', [ChartOfAccountsController::class, 'importTemplate']);
        Route::post('/chart-of-accounts/import', [ChartOfAccountsController::class, 'import']);
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
        Route::get('/journal-entries/export', [JournalEntriesController::class, 'export']);
        Route::post('/journal-entries/import', [JournalEntriesController::class, 'import']);
        Route::post('/journal-entries/bulk-delete', [JournalEntriesController::class, 'bulkDestroy']);
        Route::post('/journal-entries/bulk-post', [JournalEntriesController::class, 'bulkPost'])
            ->middleware(EnsureAccountingPostAccess::class);
        Route::get('/journal-entries/{id}', [JournalEntriesController::class, 'show']);
        Route::post('/journal-entries', [JournalEntriesController::class, 'store']);
        Route::put('/journal-entries/{id}', [JournalEntriesController::class, 'update']);
        Route::delete('/journal-entries/{id}', [JournalEntriesController::class, 'destroy']);
        Route::post('/journal-entries/validate-date', [JournalEntriesController::class, 'validateDate']);

        // Workstream 2 (2026-04-15): Fine-grained entitlement enforcement
        // Posting requires accounting.post permission
        Route::post('/journal-entries/{id}/post', [JournalEntriesController::class, 'post'])
            ->middleware(EnsureAccountingPostAccess::class);
        // Reversal requires accounting.reverse permission
        Route::post('/journal-entries/{id}/reverse', [JournalEntriesController::class, 'reverse'])
            ->middleware(EnsureAccountingReverseAccess::class);
        // Partial reversal requires accounting.reverse permission
        Route::post('/journal-entries/{id}/partial-reverse', [JournalEntriesController::class, 'partialReverse'])
            ->middleware(EnsureAccountingReverseAccess::class);

        // Reports
        Route::get('/trial-balance', [AccountingController::class, 'trialBalance'])
            ->middleware(RequirePermission::using('reports.view'));
        Route::get('/ledger/{account}', [AccountingController::class, 'ledger'])
            ->middleware(RequirePermission::using('reports.view'));
        Route::get('/balance-sheet', [AccountingController::class, 'balanceSheet'])
            ->middleware(RequirePermission::using('reports.view'));
        Route::get('/profit-and-loss', [AccountingController::class, 'profitAndLoss'])
            ->middleware(RequirePermission::using('reports.view'));

        // Period-End Processes
        Route::post('/fx-revaluation', [AccountingController::class, 'fxRevaluation']);

        // Vendor Bills (with tenant context)
        Route::get('/vendor-bills', [VendorBillsController::class, 'index']);
        Route::get('/vendor-bills/eligible', [VendorBillsController::class, 'eligibleBills']);
        Route::get('/vendor-bills/{id}', [VendorBillsController::class, 'show']);
        Route::post('/vendor-bills', [VendorBillsController::class, 'store']);
        Route::put('/vendor-bills/{id}', [VendorBillsController::class, 'update']);
        Route::delete('/vendor-bills/{id}', [VendorBillsController::class, 'destroy']);
        Route::post('/vendor-bills/{id}/post', [VendorBillsController::class, 'post']);
        Route::post('/vendor-bills/{id}/approve', [VendorBillsController::class, 'approve']);
        Route::post('/vendor-bills/{id}/reject', [VendorBillsController::class, 'reject']);

        // Accounting-Origin Documents (direct AR/AP creation by accountants)
        Route::post('/origin/invoices', [AccountingOriginController::class, 'storeInvoice']);
        Route::post('/origin/invoices/{id}/post', [AccountingOriginController::class, 'postInvoice'])
            ->middleware(EnsureAccountingPostAccess::class);
        Route::post('/origin/bills', [AccountingOriginController::class, 'storeBill']);
        Route::post('/origin/bills/{id}/post', [AccountingOriginController::class, 'postBill'])
            ->middleware(EnsureAccountingPostAccess::class);

        // Aged Receivables Report
        Route::get('/reports/aged-receivables', [AgedReceivablesController::class, 'index']);
        Route::get('/reports/aged-receivables/details', [AgedReceivablesController::class, 'details']);

        // Company-wide aging matrix
        Route::get('/reports/aging', [AgedReceivablesController::class, 'agingReport']);

        // Aged Payables Report
        Route::get('/reports/aged-payables', [AgedPayablesController::class, 'index']);
        Route::get('/reports/aged-payables/details', [AgedPayablesController::class, 'details']);

        // Bank Reconciliation
        Route::get('/reconciliation/bank-accounts', [BankReconciliationController::class, 'getBankAccounts']);
        Route::get('/reconciliation/transactions/{bankAccountId}', [BankReconciliationController::class, 'getTransactions']);
        Route::post('/reconciliation/complete', [BankReconciliationController::class, 'complete']);
        Route::post('/reconciliation/add-transaction', [BankReconciliationController::class, 'addTransaction']);
        Route::get('/reconciliation/history/{bankAccountId}', [BankReconciliationController::class, 'history']);

        // Persisted bank-statement ingestion (statement records only; never posts GL entries)
        Route::post('/reconciliation/statement-imports/preview', [BankStatementImportController::class, 'preview'])
            ->middleware(RequirePermission::using('reconciliation.import'));
        Route::post('/reconciliation/statement-imports', [BankStatementImportController::class, 'store'])
            ->middleware(RequirePermission::using('reconciliation.import'));
        Route::get('/reconciliation/statement-imports', [BankStatementImportController::class, 'index'])
            ->middleware(RequirePermission::using('reconciliation.view'));
        Route::get('/reconciliation/statement-imports/{importId}', [BankStatementImportController::class, 'show'])
            ->middleware(RequirePermission::using('reconciliation.view'));
        Route::get('/reconciliation/statement-imports/{importId}/errors', [BankStatementImportController::class, 'errors'])
            ->middleware(RequirePermission::using('reconciliation.view'));
        Route::get('/reconciliation/statement-imports/{importId}/source', [BankStatementImportController::class, 'source'])
            ->middleware(RequirePermission::using('reconciliation.view'));
        Route::post('/reconciliation/statement-imports/{importId}/reverse', [BankStatementImportController::class, 'reverse'])
            ->middleware(RequirePermission::using('reconciliation.reverse'));
        Route::get('/reconciliation/statement-transactions', [BankStatementTransactionController::class, 'index'])
            ->middleware(RequirePermission::using('reconciliation.view'));
        Route::get('/reconciliation/statement-transactions/{transactionId}', [BankStatementTransactionController::class, 'show'])
            ->middleware(RequirePermission::using('reconciliation.view'));
        Route::post('/reconciliation/statement-transactions/{transactionId}/exclude', [BankStatementTransactionController::class, 'exclude'])
            ->middleware(RequirePermission::using('reconciliation.exclude'));
        Route::post('/reconciliation/statement-transactions/{transactionId}/restore', [BankStatementTransactionController::class, 'restore'])
            ->middleware(RequirePermission::using('reconciliation.exclude'));
        Route::post('/reconciliation/statement-transactions/{transactionId}/adjustment', [BankStatementAdjustmentController::class, 'store'])
            ->middleware(RequirePermission::using('reconciliation.adjust'));
        Route::post('/reconciliation/statement-adjustments/{adjustmentId}/approve', [BankStatementAdjustmentController::class, 'approve'])
            ->middleware(RequirePermission::using('reconciliation.adjust'));
        Route::post('/reconciliation/statement-imports/{importId}/suggest-matches', [BankStatementMatchController::class, 'suggest'])
            ->middleware(RequirePermission::using('reconciliation.match'));
        Route::post('/reconciliation/statement-matches', [BankStatementMatchController::class, 'store'])
            ->middleware(RequirePermission::using('reconciliation.match'));
        Route::post('/reconciliation/statement-matches/group', [BankStatementMatchController::class, 'storeGroup'])
            ->middleware(RequirePermission::using('reconciliation.match'));
        Route::post('/reconciliation/statement-matches/bulk-accept', [BankStatementMatchController::class, 'bulkAccept'])
            ->middleware(RequirePermission::using('reconciliation.match'));
        Route::delete('/reconciliation/statement-matches/{matchId}', [BankStatementMatchController::class, 'destroy'])
            ->middleware(RequirePermission::using('reconciliation.match'));
        Route::post('/reconciliation/statement-reconciliations/validate', [BankStatementReconciliationController::class, 'validateReconciliation'])
            ->middleware(RequirePermission::using('reconciliation.complete'));
        Route::post('/reconciliation/statement-reconciliations/complete', [BankStatementReconciliationController::class, 'complete'])
            ->middleware(RequirePermission::using('reconciliation.complete'));
        Route::post('/reconciliation/statement-reconciliations/{reconciliationId}/reverse', [BankStatementReconciliationController::class, 'reverse'])
            ->middleware(RequirePermission::using('reconciliation.reverse'));
        Route::post('/reconciliation/statement-reconciliation-reversals/{reversalId}/approve', [BankStatementReconciliationController::class, 'approveReversal'])
            ->middleware(RequirePermission::using('reconciliation.reverse'));

        // Auto-Matching
        Route::post('/reconciliation/auto-match/suggest', [AutoMatchController::class, 'suggestMatches']);
        Route::post('/reconciliation/auto-match/accept', [AutoMatchController::class, 'acceptMatches']);
        Route::post('/reconciliation/auto-match/bulk-accept', [AutoMatchController::class, 'bulkAcceptHighConfidence']);
        Route::get('/reconciliation/auto-match/statistics/{bankAccountId}', [AutoMatchController::class, 'getStatistics']);

        // Subledger to GL Reconciliation
        Route::post('/accounting/reconciliation/ar-gl', [SubledgerReconciliationController::class, 'reconcileAR']);
        Route::post('/accounting/reconciliation/ap-gl', [SubledgerReconciliationController::class, 'reconcileAP']);
        Route::get('/accounting/reports/ar-gl-reconciliation', [SubledgerReconciliationController::class, 'getARReport']);
        Route::get('/accounting/reports/ap-gl-reconciliation', [SubledgerReconciliationController::class, 'getAPReport']);
        Route::get('/accounting/reconciliation/history/{type}', [SubledgerReconciliationController::class, 'getHistory']);

        // Reconciliation Exceptions
        Route::get('/reconciliation/exceptions', [ReconciliationExceptionController::class, 'index']);
        Route::post('/reconciliation/exceptions', [ReconciliationExceptionController::class, 'store']);
        Route::get('/reconciliation/exceptions/{id}', [ReconciliationExceptionController::class, 'show']);
        Route::put('/reconciliation/exceptions/{id}', [ReconciliationExceptionController::class, 'update']);
        Route::post('/reconciliation/exceptions/{id}/resolve', [ReconciliationExceptionController::class, 'resolve']);
        Route::post('/reconciliation/exceptions/{id}/escalate', [ReconciliationExceptionController::class, 'escalate']);
        Route::get('/reconciliation/exceptions/aging', [ReconciliationExceptionController::class, 'agingStatistics']);
        Route::post('/reconciliation/exceptions/age-all', [ReconciliationExceptionController::class, 'ageAll']);

        // Fixed Assets
        Route::get('/assets', [FixedAssetsController::class, 'index']);
        Route::get('/assets/export', [FixedAssetsController::class, 'export']);
        Route::post('/assets/import', [FixedAssetsController::class, 'import']);
        Route::get('/assets/{id}', [FixedAssetsController::class, 'show']);
        Route::post('/assets', [FixedAssetsController::class, 'store']);
        Route::put('/assets/{id}', [FixedAssetsController::class, 'update']);
        Route::delete('/assets/{id}', [FixedAssetsController::class, 'destroy']);
        Route::get('/assets/{id}/depreciation', [FixedAssetsController::class, 'getDepreciationSchedule']);
        Route::post('/assets/{id}/dispose', [FixedAssetsController::class, 'dispose']);
        Route::post('/assets/{id}/impair', [FixedAssetsController::class, 'impair'])
            ->middleware(RequirePermission::using('accounting.write'));

        Route::post('/assets/{id}/generate-schedule', [FixedAssetsController::class, 'generateSchedule']);
        Route::post('/assets/{id}/post-pending-schedules', [FixedAssetsController::class, 'postPendingSchedules']);
        Route::post('/assets/calculate-depreciation', [FixedAssetsController::class, 'calculateDepreciation']);

        // Lock Dates
        // Workstream 2 (2026-04-15): Lock management requires accounting.lock permission
        Route::middleware(EnsureAccountingLockManageAccess::class)->group(function () {
            Route::post('/lock-dates', [LockDatesController::class, 'store']);
            Route::put('/lock-dates/{id}', [LockDatesController::class, 'update']);
            Route::delete('/lock-dates/{id}', [LockDatesController::class, 'destroy']);
            Route::post('/lock-dates/request-override', [LockDatesController::class, 'requestOverride']);
            Route::post('/lock-dates/overrides/{id}/approve', [LockDatesController::class, 'approveOverride']);
        });

        // Read-only lock date endpoints
        Route::get('/lock-dates', [LockDatesController::class, 'index']);
        Route::get('/lock-dates/current', [LockDatesController::class, 'current']);
        Route::get('/lock-dates/statistics', [LockDatesController::class, 'statistics']);
        Route::get('/lock-dates/{id}', [LockDatesController::class, 'show']);
        Route::post('/lock-dates/validate', [LockDatesController::class, 'validateDate']);
        Route::get('/lock-dates/overrides/history', [LockDatesController::class, 'overrides']);

        // Fiscal Periods — CRUD + lifecycle (lock / unlock / close / reopen)
        // Workstream 2 (2026-04-15): Fiscal period management requires accounting.lock permission
        Route::middleware(EnsureAccountingLockManageAccess::class)->group(function () {
            Route::post('/fiscal-periods', [FiscalPeriodsController::class, 'store']);
            Route::post('/fiscal-periods/generate-year', [FiscalPeriodsController::class, 'generateYear']);
            Route::put('/fiscal-periods/{id}', [FiscalPeriodsController::class, 'update']);
            Route::delete('/fiscal-periods/{id}', [FiscalPeriodsController::class, 'destroy']);
            Route::post('/fiscal-periods/{id}/lock', [FiscalPeriodsController::class, 'lock']);
            Route::post('/fiscal-periods/{id}/unlock', [FiscalPeriodsController::class, 'unlock']);
            Route::post('/fiscal-periods/{id}/close', [FiscalPeriodsController::class, 'close']);
            Route::post('/fiscal-periods/{id}/reopen', [FiscalPeriodsController::class, 'reopen']);
        });

        // Read-only fiscal period endpoints
        Route::get('/fiscal-periods', [FiscalPeriodsController::class, 'index']);
        Route::get('/fiscal-periods/{id}', [FiscalPeriodsController::class, 'show']);

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
        Route::post('/onboarding/sessions/{id}/back', [OnboardingSessionsController::class, 'back']);
        Route::post('/onboarding/sessions/{id}/resume', [OnboardingSessionsController::class, 'resume']);
        Route::post('/onboarding/sessions/{id}/abandon', [OnboardingSessionsController::class, 'abandon']);
        Route::post('/onboarding/sessions/{id}/skip', [OnboardingSessionsController::class, 'skip']);

        // Entity Management (Phase 4 — Multi-Entity)
        Route::get('/groups', [EntityManagementController::class, 'indexGroups']);
        Route::post('/groups', [EntityManagementController::class, 'storeGroup'])
            ->middleware(RequirePermission::using('entities.manage'));
        Route::get('/groups/{id}', [EntityManagementController::class, 'showGroup']);
        Route::put('/groups/{id}', [EntityManagementController::class, 'updateGroup'])
            ->middleware(RequirePermission::using('entities.manage'));
        Route::delete('/groups/{id}', [EntityManagementController::class, 'destroyGroup'])
            ->middleware(RequirePermission::using('entities.manage'));

        Route::get('/legal-entities', [EntityManagementController::class, 'indexEntities']);
        Route::post('/legal-entities', [EntityManagementController::class, 'storeEntity'])
            ->middleware(RequirePermission::using('entities.manage'));
        Route::get('/legal-entities/{id}', [EntityManagementController::class, 'showEntity']);
        Route::put('/legal-entities/{id}', [EntityManagementController::class, 'updateEntity'])
            ->middleware(RequirePermission::using('entities.manage'));
        Route::delete('/legal-entities/{id}', [EntityManagementController::class, 'destroyEntity'])
            ->middleware(RequirePermission::using('entities.manage'));

        Route::get('/branches', [EntityManagementController::class, 'indexBranches']);
        Route::post('/branches', [EntityManagementController::class, 'storeBranch'])
            ->middleware(RequirePermission::using('entities.manage'));
        Route::get('/branches/{id}', [EntityManagementController::class, 'showBranch']);
        Route::put('/branches/{id}', [EntityManagementController::class, 'updateBranch'])
            ->middleware(RequirePermission::using('entities.manage'));
        Route::delete('/branches/{id}', [EntityManagementController::class, 'destroyBranch'])
            ->middleware(RequirePermission::using('entities.manage'));

        Route::get('/entity-books', [EntityManagementController::class, 'indexBooks']);
        Route::post('/entity-books', [EntityManagementController::class, 'storeBook'])
            ->middleware(RequirePermission::using('entities.manage'));
        Route::get('/entity-books/{id}', [EntityManagementController::class, 'showBook']);
        Route::put('/entity-books/{id}', [EntityManagementController::class, 'updateBook'])
            ->middleware(RequirePermission::using('entities.manage'));
        Route::delete('/entity-books/{id}', [EntityManagementController::class, 'destroyBook'])
            ->middleware(RequirePermission::using('entities.manage'));

        Route::get('/legal-entities/{entityId}/modules', [EntityManagementController::class, 'indexModules']);
        Route::post('/legal-entities/{entityId}/modules/activate', [EntityManagementController::class, 'activateModule'])
            ->middleware(RequirePermission::using('entities.manage'));
        Route::post('/legal-entities/{entityId}/modules/deactivate', [EntityManagementController::class, 'deactivateModule'])
            ->middleware(RequirePermission::using('entities.manage'));
        Route::post('/legal-entities/{entityId}/modules/industry-pack', [EntityManagementController::class, 'applyIndustryPack'])
            ->middleware(RequirePermission::using('entities.manage'));

        // Currencies — read endpoints are open to any tenant-authenticated user;
        // mutations require settings.write so only Owner/Admin can toggle
        // activation (Issue: "no where to deactivate currency").
        Route::get('/currencies', [CurrenciesController::class, 'index']);
        Route::get('/currencies/active', [CurrenciesController::class, 'active']);
        Route::get('/currencies/{code}', [CurrenciesController::class, 'show']);
        Route::post('/currencies', [CurrenciesController::class, 'store'])
            ->middleware(RequirePermission::using('settings.write'));
        Route::put('/currencies/{code}', [CurrenciesController::class, 'update'])
            ->middleware(RequirePermission::using('settings.write'));
        Route::delete('/currencies/{code}', [CurrenciesController::class, 'destroy'])
            ->middleware(RequirePermission::using('settings.write'));

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

        // Tenant branding (name + logo) for non-Template-Builder documents
        // (report PDF/print exports) — any tenant user, no settings gate.
        Route::get('/settings/branding', [CompanySettingsController::class, 'branding']);

        // Company Settings — same OWNER/ADMIN gate as the top-level /settings routes.
        Route::get('/settings', [CompanySettingsController::class, 'index'])
            ->middleware(RequirePermission::using('settings.view'));
        Route::put('/settings', [CompanySettingsController::class, 'update'])
            ->middleware(RequirePermission::using('settings.write'));
        // Logo upload/delete: routes removed until controller methods are implemented
        Route::get('/settings/fiscal-year', [CompanySettingsController::class, 'fiscalYear'])
            ->middleware(RequirePermission::using('settings.view'));
        Route::put('/settings/fiscal-year', [CompanySettingsController::class, 'updateFiscalYear'])
            ->middleware(RequirePermission::using('settings.write'));
        Route::get('/settings/currencies', [CompanySettingsController::class, 'currencies'])
            ->middleware(RequirePermission::using('settings.view'));
        Route::put('/settings/currencies', [CompanySettingsController::class, 'updateCurrencies'])
            ->middleware(RequirePermission::using('settings.write'));
        Route::get('/settings/accounts', [CompanySettingsController::class, 'accounts'])
            ->middleware(RequirePermission::using('settings.view'));
        Route::put('/settings/accounts', [CompanySettingsController::class, 'updateAccounts'])
            ->middleware(RequirePermission::using('settings.write'));
        // (document-formats + sales settings moved out of the accounting prefix
        //  group below so they're reachable as /api/v1/settings/... directly)

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

        // Customer Credit Notes (must be before /customers/{id} to avoid route conflict)
        Route::get('/customers/credit-notes', [CustomerCreditNotesController::class, 'index']);
        Route::get('/customers/credit-notes/{creditNote}', [CustomerCreditNotesController::class, 'show']);
        Route::post('/customers/credit-notes', [CustomerCreditNotesController::class, 'store']);
        Route::put('/customers/credit-notes/{creditNote}', [CustomerCreditNotesController::class, 'update']);
        Route::delete('/customers/credit-notes/{creditNote}', [CustomerCreditNotesController::class, 'destroy']);
        Route::post('/customers/credit-notes/{creditNote}/post', [CustomerCreditNotesController::class, 'post']);
        Route::post('/customers/credit-notes/{creditNote}/void', [CustomerCreditNotesController::class, 'void'])
            ->middleware(\App\Http\Middleware\RequirePermission::using('customers.write'));
        Route::get('/customers/invoices/{invoiceId}/credit-notes', [CustomerCreditNotesController::class, 'getByInvoice']);
        Route::post('/customers/credit-notes/validate', [CustomerCreditNotesController::class, 'validateCreditNote']);

        // Customers List
        Route::get('/customers', [CustomersController::class, 'index'])
            ->middleware(RequirePermission::using('customers.view'));
        Route::post('/customers/import', [CustomersController::class, 'import'])
            ->middleware(\App\Http\Middleware\RequirePermission::using('customers.write'));
        Route::post('/customers/import/{batch}/commit', [CustomersController::class, 'commitImport'])
            ->middleware(\App\Http\Middleware\RequirePermission::using('customers.write'));
        Route::get('/customers/{id}', [CustomersController::class, 'show'])
            ->whereNumber('id')
            ->middleware(RequirePermission::using('customers.view'));

        // Vendor Credit Notes (must be before /vendors/{id} to avoid route conflict)
        Route::get('/vendors/credit-notes', [VendorCreditNotesController::class, 'index']);
        Route::get('/vendors/credit-notes/{creditNote}', [VendorCreditNotesController::class, 'show']);
        Route::post('/vendors/credit-notes', [VendorCreditNotesController::class, 'store']);
        Route::put('/vendors/credit-notes/{creditNote}', [VendorCreditNotesController::class, 'update']);
        Route::delete('/vendors/credit-notes/{creditNote}', [VendorCreditNotesController::class, 'destroy']);
        Route::post('/vendors/credit-notes/{creditNote}/post', [VendorCreditNotesController::class, 'post']);
        Route::get('/vendors/bills/{billId}/credit-notes', [VendorCreditNotesController::class, 'getByBill']);
        Route::post('/vendors/credit-notes/validate', [VendorCreditNotesController::class, 'validate']);

        // Vendor Debit Notes
        Route::get('/vendors/debit-notes', [VendorDebitNotesController::class, 'index']);
        Route::get('/vendors/debit-notes/{debitNote}', [VendorDebitNotesController::class, 'show']);
        Route::post('/vendors/debit-notes', [VendorDebitNotesController::class, 'store']);
        Route::put('/vendors/debit-notes/{debitNote}', [VendorDebitNotesController::class, 'update']);
        Route::delete('/vendors/debit-notes/{debitNote}', [VendorDebitNotesController::class, 'destroy']);
        Route::post('/vendors/debit-notes/{debitNote}/post', [VendorDebitNotesController::class, 'post']);
        Route::get('/vendors/bills/{billId}/debit-notes', [VendorDebitNotesController::class, 'getByBill']);
        Route::post('/vendors/debit-notes/validate', [VendorDebitNotesController::class, 'validate']);

        // Vendors List
        Route::get('/vendors', [VendorsController::class, 'index']);
        Route::get('/vendors/{id}', [VendorsController::class, 'show'])->whereNumber('id');
        Route::post('/vendors', [VendorsController::class, 'store']);
        Route::put('/vendors/{id}', [VendorsController::class, 'update']);
        Route::delete('/vendors/{id}', [VendorsController::class, 'destroy']);
        Route::post('/vendors/import', [VendorsController::class, 'import']);

        // AR Transactions Ledger
        Route::get('/customers/transactions', [ARTransactionsController::class, 'index']);
        Route::get('/customers/{customerId}/balance', [ARTransactionsController::class, 'getBalance']);
        Route::get('/customers/transactions/export', [ARTransactionsController::class, 'export']);

        // AP Transactions Ledger
        Route::get('/vendors/transactions', [APTransactionsController::class, 'index']);
        Route::get('/vendors/{vendorId}/balance', [APTransactionsController::class, 'getBalance']);
        Route::get('/vendors/transactions/export', [APTransactionsController::class, 'export']);

        // Customer Statements (legacy range-based)
        Route::post('/customers/{customer}/statement', [CustomerStatementController::class, 'generate']);
        Route::get('/customers/statements/history', [CustomerStatementController::class, 'history']);
        Route::get('/customers/statements/{statement}', [CustomerStatementController::class, 'show']);

        // Statement of Account reads (issue #195): entity-scoped exactly like
        // generate/history/show above — previously they sat outside this group
        // and leaked every legal entity's customer financials.
        Route::get('/customers/{customer}/statement/compare', [CustomerStatementController::class, 'compare'])
            ->middleware(RequirePermission::using('customers.view'));
        Route::get('/customers/{customer}/statement/compare/pdf', [CustomerStatementController::class, 'comparePdf'])
            ->middleware(RequirePermission::using('customers.view'));
        Route::get('/customers/{customer}/statement', [CustomerStatementController::class, 'statement'])
            ->middleware(RequirePermission::using('customers.view'));
    });

    // Medical Cases (issues #161/#186: previously no permission middleware at all)
    Route::middleware([ResolveEntityContext::class, EnsureEntityAccess::class])->group(function () {
        Route::apiResource('medical-cases', MedicalCaseController::class)
            ->only(['index', 'show'])
            ->middleware(RequirePermission::using('medical_cases.view'));
        Route::apiResource('medical-cases', MedicalCaseController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware(RequirePermission::using('medical_cases.write'));

        // Issue #185: report route must precede the resource {medical_case} binding.
        Route::get('/medical-cases/reports/performance', [MedicalCaseController::class, 'performanceReport'])
            ->middleware(RequirePermission::using('medical_cases.view'));

        Route::middleware(RequirePermission::using('medical_cases.write'))->group(function () {
            Route::post('/medical-cases/{id}/allocate', [MedicalCaseController::class, 'allocate']);
            Route::post('/medical-cases/{id}/deliver', [MedicalCaseController::class, 'deliver']);
            Route::post('/medical-cases/{id}/invoice', [MedicalCaseController::class, 'invoice']);
            Route::post('/medical-cases/{id}/complete', [MedicalCaseController::class, 'complete']);
            Route::post('/medical-cases/{id}/cancel', [MedicalCaseController::class, 'cancel']);
        });
    });

    // Bonus Schemes (issues #161/#186)
    Route::middleware([ResolveEntityContext::class, EnsureEntityAccess::class])->group(function () {
        Route::apiResource('bonus-schemes', BonusSchemeController::class)
            ->only(['index', 'show'])
            ->middleware(RequirePermission::using('bonus_schemes.view'));
        Route::apiResource('bonus-schemes', BonusSchemeController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware(RequirePermission::using('bonus_schemes.write'));

        Route::middleware(RequirePermission::using('bonus_schemes.write'))->group(function () {
            Route::post('/bonus-schemes/{id}/activate', [BonusSchemeController::class, 'activate']);
            Route::post('/bonus-schemes/{id}/deactivate', [BonusSchemeController::class, 'deactivate']);
        });
    });

    // Customer Bonus Scheme Assignments (issues #161/#186 — same bonus_schemes domain)
    Route::middleware([ResolveEntityContext::class, EnsureEntityAccess::class])->group(function () {
        Route::apiResource('customer-bonus-schemes', CustomerBonusSchemeController::class)
            ->only(['index', 'show'])
            ->middleware(RequirePermission::using('bonus_schemes.view'));
        Route::apiResource('customer-bonus-schemes', CustomerBonusSchemeController::class)
            ->only(['store', 'update', 'destroy'])
            ->middleware(RequirePermission::using('bonus_schemes.write'));

        Route::middleware(RequirePermission::using('bonus_schemes.write'))->group(function () {
            Route::post('/customer-bonus-schemes/{id}/suspend', [CustomerBonusSchemeController::class, 'suspend']);
            Route::post('/customer-bonus-schemes/{id}/reactivate', [CustomerBonusSchemeController::class, 'reactivate']);
        });
    });

    // Per-customer aging (customer detail page — no entity/accounting middleware needed)
    // Issue #125 (HIGH): exposes customer AR aging — gate with customers.view.
    Route::get('/customers/{customerId}/aging', [AgedReceivablesController::class, 'customerAging'])
        ->middleware(RequirePermission::using('customers.view'));
});
