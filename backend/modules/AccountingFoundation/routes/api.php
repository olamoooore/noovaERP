<?php

use Illuminate\Support\Facades\Route;
use Modules\AccountingFoundation\Http\Controllers\OpeningBalanceController;

/*
|--------------------------------------------------------------------------
| ACC-001 Opening Balance Routes
|--------------------------------------------------------------------------
|
| Issue #70 (ACC-DEC-060 Track B): opening balance lifecycle endpoints.
| All mutations enforce fail-closed rules, SoD, and lifecycle transitions.
| ACC-001 never writes JournalEntry directly — posting goes through
| ACC-002 PostingEngine.
|
*/

// ── Reads: accounting.opening_balance.view ─────────────────────────
Route::middleware([\App\Http\Middleware\RequirePermission::using('accounting.opening_balance.view')])->group(function () {
    Route::get('/opening-balances', [OpeningBalanceController::class, 'index']);
    Route::get('/opening-balances/{id}', [OpeningBalanceController::class, 'show']);
    Route::get('/opening-balances/{id}/audit-trail', [OpeningBalanceController::class, 'auditTrail']);
});

// ── Writes: accounting.opening_balance.write ───────────────────────
Route::middleware([\App\Http\Middleware\RequirePermission::using('accounting.opening_balance.write')])->group(function () {
    Route::post('/opening-balances', [OpeningBalanceController::class, 'store']);
    Route::post('/opening-balances/{id}/lines', [OpeningBalanceController::class, 'captureLines']);
    Route::post('/opening-balances/{id}/validate', [OpeningBalanceController::class, 'validateBatch']);
    Route::post('/opening-balances/{id}/reconcile', [OpeningBalanceController::class, 'reconcile']);
    Route::post('/opening-balances/{id}/submit-approval', [OpeningBalanceController::class, 'submitForApproval']);
    Route::post('/opening-balances/{id}/supersede', [OpeningBalanceController::class, 'supersede']);
});

// ── Approval: accounting.opening_balance.approve (SoD) ────────────
Route::middleware([\App\Http\Middleware\RequirePermission::using('accounting.opening_balance.approve')])->group(function () {
    Route::post('/opening-balances/{id}/approve', [OpeningBalanceController::class, 'approve']);
});

// ── Posting: accounting.opening_balance.post ───────────────────────
Route::middleware([\App\Http\Middleware\RequirePermission::using('accounting.opening_balance.post')])->group(function () {
    Route::post('/opening-balances/{id}/authorise', [OpeningBalanceController::class, 'authorise']);
    Route::post('/opening-balances/{id}/post', [OpeningBalanceController::class, 'post']);
});
