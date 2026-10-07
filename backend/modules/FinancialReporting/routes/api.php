<?php

use Illuminate\Support\Facades\Route;
use Modules\FinancialReporting\Controllers\AnalyticsController;
use Modules\FinancialReporting\Controllers\CacheController;
use Modules\FinancialReporting\Controllers\FinancialReportController;
use Modules\FinancialReporting\Controllers\InventoryReportController;
use Modules\FinancialReporting\Controllers\ReportDashboardWidgetController;
use Modules\FinancialReporting\Controllers\ReportExportController;
use Modules\FinancialReporting\Controllers\ReportScheduleController;
use Modules\FinancialReporting\Controllers\ReportTemplateController;
use Modules\FinancialReporting\Controllers\SalesRecordsController;
use Modules\FinancialReporting\Controllers\SavedReportViewController;
use Modules\FinancialReporting\Controllers\SavedReportViewScheduleController;
use Modules\FinancialReporting\Controllers\TraceabilityReportController;

/*
|--------------------------------------------------------------------------
| Financial Reporting API Routes
|--------------------------------------------------------------------------
*/

$tenantMiddleware = [
    'auth:sanctum',
    // Issue #220 audit: this standalone group also lacked impersonation support.
    \App\Http\Middleware\ImpersonationMiddleware::class,
    \App\Http\Middleware\SetTenantFromUserPreference::class,
    \App\Http\Middleware\EnsureTenantSearchPath::class,
    \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
    \App\Http\Middleware\ResolveEntityContext::class,
    \App\Http\Middleware\EnsureEntityAccess::class,
];

// Sales cockpit: the Sales dashboard's analytics plus the per-user pinned
// saved-view widgets. Readable with reports.view OR sales.view, so the Sales
// role can load its dashboard without gaining the financial reports (P&L,
// balance sheet, GL, …) that stay reports.view-only below.
Route::middleware([...$tenantMiddleware, \App\Http\Middleware\RequirePermission::using('reports.view|sales.view')])
    ->group(function () {
        Route::prefix('analytics')->name('analytics.')->group(function () {
            Route::get('/sales/by-product', [AnalyticsController::class, 'salesByProduct'])->name('sales.by-product');
            Route::get('/sales/trends', [AnalyticsController::class, 'salesTrends'])->name('sales.trends');
            Route::get('/sales/by-customer', [AnalyticsController::class, 'salesByCustomer'])->name('sales.by-customer');
            Route::get('/sales/by-category', [AnalyticsController::class, 'salesByCategory'])->name('sales.by-category');
            // Issue #596: a report row's source documents — same read model
            // and filters as the row, so it is readable wherever the row is.
            Route::get('/sales/drilldown', [AnalyticsController::class, 'salesDrilldown'])->name('sales.drilldown');
        });

        // Issue #603: per-user Sales-cockpit widgets pinning a saved view (#598).
        Route::prefix('reports/dashboard/widgets')->name('reports.dashboard.widgets.')->group(function () {
            Route::get('/', [ReportDashboardWidgetController::class, 'index'])->name('index');
            Route::post('/', [ReportDashboardWidgetController::class, 'store'])->name('store');
            Route::put('/reorder', [ReportDashboardWidgetController::class, 'reorder'])->name('reorder');
            Route::get('/{id}/data', [ReportDashboardWidgetController::class, 'data'])->name('data');
            Route::delete('/{id}', [ReportDashboardWidgetController::class, 'destroy'])->name('destroy');
        });
    });

// Issue #103: every reporting route requires at least reports.view;
// configuration/mutations additionally require reports.manage below.
Route::middleware([...$tenantMiddleware, \App\Http\Middleware\RequirePermission::using('reports.view')])
    ->group(function () {

        // Financial Reports
        Route::prefix('reports/financial')->name('reports.financial.')->group(function () {
            Route::get('/profit-loss', [FinancialReportController::class, 'profitLoss'])->name('profit-loss');
            Route::get('/balance-sheet', [FinancialReportController::class, 'balanceSheet'])->name('balance-sheet');
            Route::get('/cash-flow', [FinancialReportController::class, 'cashFlow'])->name('cash-flow');
            Route::get('/trial-balance', [FinancialReportController::class, 'trialBalance'])->name('trial-balance');
            Route::get('/general-ledger', [FinancialReportController::class, 'generalLedger'])->name('general-ledger');
        });

        // Inventory Reports
        Route::prefix('reports/inventory')->name('reports.inventory.')->group(function () {
            Route::get('/valuation', [InventoryReportController::class, 'valuation'])->name('valuation');
            Route::get('/movement', [InventoryReportController::class, 'movement'])->name('movement');
            Route::get('/reorder', [InventoryReportController::class, 'reorder'])->name('reorder');
            Route::get('/aging', [InventoryReportController::class, 'aging'])->name('aging');
            Route::get('/turnover', [InventoryReportController::class, 'turnover'])->name('turnover');
        });

        // #1020: the client's Sales Records sheet — invoice rows with Qty/S$
        // column pairs for the chosen grouping (item code/brand/supplier/
        // customer/category) and monthly totals.
        Route::get('/reports/sales-records', [SalesRecordsController::class, 'index'])
            ->name('reports.sales-records');

        // Issue #583: lot/serial traceability report (upstream receipt → stock
        // moves → delivery → medical case → invoice). reports.view comes from the
        // group; PHI masking is enforced in the controller/service.
        Route::prefix('reports/traceability')->name('reports.traceability.')->group(function () {
            Route::get('/', [TraceabilityReportController::class, 'show'])->name('show');
            Route::get('/export', [TraceabilityReportController::class, 'exportCsv'])->name('export');
        });

        // Analytics
        Route::prefix('analytics')->name('analytics.')->group(function () {
            // Sales Analytics
            Route::get('/sales/reconciliation', [AnalyticsController::class, 'salesReconciliation'])->name('sales.reconciliation');
            Route::get('/sales/by-currency', [AnalyticsController::class, 'salesByCurrency'])->name('sales.by-currency');
            Route::get('/sales/pivot', [AnalyticsController::class, 'salesPivot'])->name('sales.pivot');
            Route::get('/sales/unbilled-deliveries', [AnalyticsController::class, 'unbilledDeliveries'])->name('sales.unbilled-deliveries');
            Route::get('/sales/profit-margins', [AnalyticsController::class, 'profitMargins'])->name('sales.profit-margins');

            // Expense Analytics
            Route::get('/expenses/by-category', [AnalyticsController::class, 'expensesByCategory'])->name('expenses.by-category');
            Route::get('/expenses/by-vendor', [AnalyticsController::class, 'expensesByVendor'])->name('expenses.by-vendor');
            Route::get('/expenses/by-department', [AnalyticsController::class, 'expensesByDepartment'])->name('expenses.by-department');
            Route::get('/expenses/trends', [AnalyticsController::class, 'expenseTrends'])->name('expenses.trends');

            // Customer Analytics
            Route::get('/customers/lifetime-value', [AnalyticsController::class, 'customerLifetimeValue'])->name('customers.lifetime-value');
            Route::get('/customers/purchase-patterns', [AnalyticsController::class, 'purchasePatterns'])->name('customers.purchase-patterns');
            Route::get('/customers/retention', [AnalyticsController::class, 'customerRetention'])->name('customers.retention');
            Route::get('/customers/segmentation', [AnalyticsController::class, 'rfmSegmentation'])->name('customers.segmentation');
        });

        // Report Export
        Route::prefix('reports/export')->name('reports.export.')->group(function () {
            Route::get('/download/{file}', [ReportExportController::class, 'download'])->name('download');

            Route::middleware(\App\Http\Middleware\RequirePermission::using('reports.manage'))->group(function () {
                Route::post('/pdf', [ReportExportController::class, 'exportPdf'])->name('pdf');
                Route::post('/excel', [ReportExportController::class, 'exportExcel'])->name('excel');
                Route::post('/csv', [ReportExportController::class, 'exportCsv'])->name('csv');
                Route::post('/csv/stream', [ReportExportController::class, 'streamCsv'])->name('csv.stream');
                Route::post('/cleanup', [ReportExportController::class, 'cleanup'])->name('cleanup');
            });
        });

        // Report Schedules
        Route::prefix('reports/schedules')->name('reports.schedules.')->group(function () {
            Route::get('/', [ReportScheduleController::class, 'index'])->name('index');
            Route::get('/{id}', [ReportScheduleController::class, 'show'])->name('show');

            Route::middleware(\App\Http\Middleware\RequirePermission::using('reports.manage'))->group(function () {
                Route::post('/', [ReportScheduleController::class, 'store'])->name('store');
                Route::put('/{id}', [ReportScheduleController::class, 'update'])->name('update');
                Route::delete('/{id}', [ReportScheduleController::class, 'destroy'])->name('destroy');
                Route::post('/{id}/run', [ReportScheduleController::class, 'runNow'])->name('run');
                Route::post('/{id}/toggle', [ReportScheduleController::class, 'toggleActive'])->name('toggle');
            });
        });

        // Issue #598 (RPT-UX3): saved sales-report views and their schedules.
        // reports.view (the group permission) also guards these mutations — the
        // feature introduces no new permission; owner-only actions are enforced in
        // the controllers (403).
        Route::prefix('reports/saved-views')->name('reports.saved-views.')->group(function () {
            Route::get('/', [SavedReportViewController::class, 'index'])->name('index');
            Route::get('/recipients', [SavedReportViewController::class, 'recipients'])->name('recipients');
            Route::post('/', [SavedReportViewController::class, 'store'])->name('store');

            Route::get('/{id}/schedules', [SavedReportViewScheduleController::class, 'index'])->name('schedules.index');
            Route::post('/{id}/schedules', [SavedReportViewScheduleController::class, 'store'])->name('schedules.store');
            Route::put('/{id}/schedules/{scheduleId}', [SavedReportViewScheduleController::class, 'update'])->name('schedules.update');
            Route::delete('/{id}/schedules/{scheduleId}', [SavedReportViewScheduleController::class, 'destroy'])->name('schedules.destroy');
            Route::post('/{id}/schedules/{scheduleId}/run', [SavedReportViewScheduleController::class, 'run'])->name('schedules.run');

            Route::put('/{id}', [SavedReportViewController::class, 'update'])->name('update');
            Route::delete('/{id}', [SavedReportViewController::class, 'destroy'])->name('destroy');
            Route::post('/{id}/default', [SavedReportViewController::class, 'setDefault'])->name('default');
            // Issue #603: one-click favourite (owner-only; enforced in the controller).
            Route::post('/{id}/favourite', [SavedReportViewController::class, 'setFavourite'])->name('favourite');
        });

        // Issue #603: per-user Sales-cockpit widgets pinning a saved view (#598).
        // Carved out of this group above (reports.view|sales.view).

        // Cache Management
        Route::prefix('reports/cache')->name('reports.cache.')->group(function () {
            Route::get('/stats', [CacheController::class, 'stats'])->name('stats');
            Route::get('/{cacheKey}', [CacheController::class, 'show'])->name('show');

            Route::middleware(\App\Http\Middleware\RequirePermission::using('reports.manage'))->group(function () {
                Route::post('/clear-all', [CacheController::class, 'clearAll'])->name('clear-all');
                Route::post('/clear-expired', [CacheController::class, 'clearExpired'])->name('clear-expired');
                Route::post('/clear-by-type', [CacheController::class, 'clearByType'])->name('clear-by-type');
                Route::post('/warm', [CacheController::class, 'warmCache'])->name('warm');
                Route::delete('/{cacheKey}', [CacheController::class, 'destroy'])->name('destroy');
            });
        });

        // Report Templates
        Route::prefix('reports/templates')->name('reports.templates.')->group(function () {
            Route::get('/', [ReportTemplateController::class, 'index'])->name('index');
            Route::get('/{id}', [ReportTemplateController::class, 'show'])->name('show');

            Route::middleware(\App\Http\Middleware\RequirePermission::using('reports.manage'))->group(function () {
                Route::post('/', [ReportTemplateController::class, 'store'])->name('store');
                Route::put('/{id}', [ReportTemplateController::class, 'update'])->name('update');
                Route::delete('/{id}', [ReportTemplateController::class, 'destroy'])->name('destroy');
                Route::post('/{id}/duplicate', [ReportTemplateController::class, 'duplicate'])->name('duplicate');
                Route::post('/{id}/share', [ReportTemplateController::class, 'share'])->name('share');
                Route::post('/{id}/unshare', [ReportTemplateController::class, 'unshare'])->name('unshare');
                Route::post('/{id}/apply', [ReportTemplateController::class, 'apply'])->name('apply');
            });
        });
    });
