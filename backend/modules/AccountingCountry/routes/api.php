<?php

use App\Http\Middleware\RequirePermission;
use Illuminate\Support\Facades\Route;
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
    });
