<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\TemplateBuilder\Http\Controllers\DocumentRenderController;
use Modules\TemplateBuilder\Http\Controllers\MergeFieldController;
use Modules\TemplateBuilder\Http\Controllers\TemplateController;
use Modules\TemplateBuilder\Http\Controllers\TemplateEditorController;

/*
|--------------------------------------------------------------------------
| Template Builder — tenant API routes
|--------------------------------------------------------------------------
| Auto-loaded by ModuleManager inside the authenticated api/v1 group
| (auth:sanctum + tenant schema middleware is already applied).
*/

// ─── Consumer render endpoints (the buttons existing pages call) ────────────
// These live at the resource path so existing detail-page buttons work.
Route::get('/invoices/{id}/pdf', [DocumentRenderController::class, 'invoicePdf']);
Route::get('/invoices/{id}/html', [DocumentRenderController::class, 'invoiceHtml']);
Route::get('/purchase-orders/{id}/pdf', [DocumentRenderController::class, 'purchaseOrderPdf']);
Route::get('/purchase-orders/{id}/html', [DocumentRenderController::class, 'purchaseOrderHtml']);
Route::get('/payments/{id}/pdf', [DocumentRenderController::class, 'paymentReceiptPdf']);

// ─── Template management ────────────────────────────────────────────────────
Route::prefix('template-builder')->group(function () {
    Route::get('/starters', [TemplateController::class, 'starters']);
    Route::get('/active-defaults', [TemplateController::class, 'activeDefaults']);

    Route::get('/merge-fields/{documentType}', [MergeFieldController::class, 'catalog']);
    Route::get('/sample-data/{documentType}', [MergeFieldController::class, 'sample']);

    Route::post('/templates/from-starter/{documentType}', [TemplateController::class, 'createFromStarter']);

    Route::get('/templates', [TemplateController::class, 'index']);
    Route::post('/templates', [TemplateController::class, 'store']);
    Route::get('/templates/{id}', [TemplateController::class, 'show']);
    Route::put('/templates/{id}', [TemplateController::class, 'update']);
    Route::delete('/templates/{id}', [TemplateController::class, 'destroy']);
    Route::post('/templates/{id}/duplicate', [TemplateController::class, 'duplicate']);
    Route::post('/templates/{id}/publish', [TemplateController::class, 'publish']);
    Route::post('/templates/{id}/archive', [TemplateController::class, 'archive']);
    Route::post('/templates/{id}/default', [TemplateController::class, 'setDefault']);
    Route::get('/templates/{id}/sample-pdf', [TemplateController::class, 'samplePdf']);

    Route::get('/templates/{id}/versions', [TemplateController::class, 'versions']);
    Route::post('/templates/{id}/versions/{version}/restore', [TemplateController::class, 'restoreVersion']);

    Route::post('/templates/{id}/preview', [TemplateEditorController::class, 'preview']);
    Route::post('/templates/validate', [TemplateEditorController::class, 'validateDefinition']);
});
