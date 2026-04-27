<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'entitlement:ocr_bookkeeping'])->prefix('ocr')->group(function () {
    // Document upload routes
    Route::post('/documents/upload', [\Modules\OCRBookkeeping\Http\Controllers\OcrDocumentController::class, 'upload']);
    Route::post('/documents/batch-upload', [\Modules\OCRBookkeeping\Http\Controllers\OcrDocumentController::class, 'batchUpload']);
    
    // Document management routes
    Route::get('/documents', [\Modules\OCRBookkeeping\Http\Controllers\OcrDocumentController::class, 'index']);
    Route::get('/documents/{uuid}', [\Modules\OCRBookkeeping\Http\Controllers\OcrDocumentController::class, 'show']);
    Route::put('/documents/{uuid}', [\Modules\OCRBookkeeping\Http\Controllers\OcrDocumentController::class, 'update']);
    Route::delete('/documents/{uuid}', [\Modules\OCRBookkeeping\Http\Controllers\OcrDocumentController::class, 'destroy']);
    
    // Document posting routes
    Route::post('/documents/{uuid}/post', [\Modules\OCRBookkeeping\Http\Controllers\OcrDocumentController::class, 'post']);
    Route::post('/documents/{uuid}/unpost', [\Modules\OCRBookkeeping\Http\Controllers\OcrDocumentController::class, 'unpost']);
    Route::post('/documents/batch-post', [\Modules\OCRBookkeeping\Http\Controllers\OcrDocumentController::class, 'batchPost']);
    
    // Suggestions route
    Route::get('/documents/{uuid}/suggestions', [\Modules\OCRBookkeeping\Http\Controllers\OcrDocumentController::class, 'suggestions']);

    // Retry failed extraction
    Route::post('/documents/{uuid}/retry', [\Modules\OCRBookkeeping\Http\Controllers\OcrDocumentController::class, 'retry']);

    // Promotion routes (Phase 3 — OCR → canonical accounting documents)
    Route::post('/documents/{uuid}/promote/bill', [\Modules\OCRBookkeeping\Http\Controllers\OcrDocumentController::class, 'promoteToBill']);
    Route::post('/documents/{uuid}/promote/invoice', [\Modules\OCRBookkeeping\Http\Controllers\OcrDocumentController::class, 'promoteToInvoice']);
});

// File endpoint - no auth required (UUID is secure enough)
Route::get('/ocr/documents/{uuid}/file', [\Modules\OCRBookkeeping\Http\Controllers\OcrDocumentController::class, 'getFile']);
