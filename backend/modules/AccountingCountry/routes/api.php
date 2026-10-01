<?php

use App\Http\Middleware\RequirePermission;
use Illuminate\Support\Facades\Route;
use Modules\AccountingCountry\Http\Controllers\EInvoicingCapabilityController;
use Modules\AccountingCountry\Http\Controllers\StatutoryReturnController;
use Modules\AccountingCountry\Http\Controllers\TaxReportController;

/*
| Issue #93 — statutory tax-return generation (SG GST F5, MY SST-02).
| Loaded into the authenticated tenant route group by ModuleManager.
*/
Route::prefix('accounting/statutory-returns')
    ->middleware(RequirePermission::using('tax.view'))
    ->group(function () {
        Route::get('/', [StatutoryReturnController::class, 'index']);
        Route::get('/periods', [StatutoryReturnController::class, 'periods']);
        Route::get('/{country}/{period}', [StatutoryReturnController::class, 'show']);
        // Issue #265 + MRD TAX-REQ-08: file a return baseline (F7 / filing tracker)
        // and disclose F7 errors.
        Route::post('/{country}/{period}/file', [StatutoryReturnController::class, 'file'])
            ->middleware(RequirePermission::using('tax.manage'));
        Route::get('/SG/{period}/f7', [StatutoryReturnController::class, 'f7']);

        // G-14 (#274): authority response recorded against a filing.
        Route::post('/filings/{filing}/submission', [StatutoryReturnController::class, 'recordSubmissionOutcome'])
            ->middleware(RequirePermission::using('tax.policy.manage|tax.manage'));
    });

// Issue #99: honest e-invoicing capability reporting.
Route::get('/accounting/e-invoicing/capabilities', [EInvoicingCapabilityController::class, 'index'])
    ->middleware(RequirePermission::using('reports.view'));

/*
| MRD TAX-REQ-08 — tax reporting: journal, trail-to-GL reconciliation,
| filing tracker. Read-only; the underlying generators stay above.
*/
Route::prefix('accounting/tax-report')
    ->middleware(RequirePermission::using('tax.view'))
    ->group(function () {
        Route::get('/journal', [TaxReportController::class, 'journal']);
        Route::get('/reconciliation', [TaxReportController::class, 'reconciliation']);
        Route::get('/filings', [TaxReportController::class, 'filings']);
        Route::get('/periods', [\App\Http\Controllers\Api\TaxPeriodController::class, 'lockStates']);
        Route::get('/withholding', [TaxReportController::class, 'withholding']);
        // #270: remittance loop + authority schedule export.
        Route::post('/withholding/remit', [TaxReportController::class, 'remit'])
            ->middleware(RequirePermission::using('tax.policy.manage|tax.manage'));
        Route::get('/withholding/export', [TaxReportController::class, 'withholdingExport']);
    });

/*
| Tax period close/reopen (MRD R7.4). Closing locks the month: the posting
| gate refuses tax-bearing postings dated inside it, forcing amendments
| through open-period documents.
*/
Route::prefix('accounting/tax-periods')
    ->middleware([RequirePermission::using('tax.manage')])
    ->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\TaxPeriodController::class, 'index']);
        Route::post('/{period}/close', [\App\Http\Controllers\Api\TaxPeriodController::class, 'close']);
        Route::post('/{period}/reopen', [\App\Http\Controllers\Api\TaxPeriodController::class, 'reopen']);
    });
