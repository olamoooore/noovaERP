<?php

use App\Http\Middleware\RequirePermission;
use Illuminate\Support\Facades\Route;
use Modules\AccountingCountry\Http\Controllers\EInvoicingCapabilityController;
use Modules\AccountingCountry\Http\Controllers\StatutoryReturnController;

/*
| Issue #93 — statutory tax-return generation (SG GST F5, MY SST-02).
| Loaded into the authenticated tenant route group by ModuleManager.
*/
Route::prefix('accounting/statutory-returns')
    ->middleware(RequirePermission::using('reports.view'))
    ->group(function () {
        Route::get('/', [StatutoryReturnController::class, 'index']);
        Route::get('/{country}/{period}', [StatutoryReturnController::class, 'show']);
        // Issue #265: file a return (F7 baseline) and disclose F7 errors.
        Route::post('/SG/{period}/file', [StatutoryReturnController::class, 'file'])
            ->middleware(RequirePermission::using('reports.manage'));
        Route::get('/SG/{period}/f7', [StatutoryReturnController::class, 'f7']);
    });

// Issue #99: honest e-invoicing capability reporting.
Route::get('/accounting/e-invoicing/capabilities', [EInvoicingCapabilityController::class, 'index'])
    ->middleware(RequirePermission::using('reports.view'));
