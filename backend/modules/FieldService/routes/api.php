<?php

use Illuminate\Support\Facades\Route;
use Modules\FieldService\Http\Controllers\WorkOrderController;
use Modules\FieldService\Http\Controllers\WorkOrderPartsController;
use Modules\FieldService\Http\Controllers\TimeLogController;
use Modules\FieldService\Http\Controllers\TechnicianController;
use Modules\FieldService\Http\Controllers\TeamController;
use Modules\FieldService\Http\Controllers\ConfigController;
use Modules\FieldService\Http\Controllers\AnalyticsController;
use Modules\FieldService\Http\Controllers\WorksheetTemplateController;
use Modules\FieldService\Http\Controllers\WorksheetResponseController;

Route::middleware('auth:sanctum')->prefix('field-service')->group(function () {

    // ── Work Orders ───────────────────────────────────────────────────────────
    Route::get('/work-orders/calendar',  [WorkOrderController::class, 'calendar']);
    Route::get('/work-orders/gantt',     [WorkOrderController::class, 'gantt']);
    Route::get('/work-orders/map',       [WorkOrderController::class, 'map']);

    Route::apiResource('work-orders', WorkOrderController::class);

    Route::post('/work-orders/{workOrder}/status',    [WorkOrderController::class, 'updateStatus']);
    Route::post('/work-orders/{workOrder}/assign',    [WorkOrderController::class, 'assign']);
    Route::post('/work-orders/{workOrder}/note',      [WorkOrderController::class, 'addNote']);
    Route::post('/work-orders/{workOrder}/invoice',   [WorkOrderController::class, 'generateInvoice']);
    Route::post('/work-orders/{workOrder}/rating',    [WorkOrderController::class, 'submitRating']);
    Route::post('/work-orders/{workOrder}/duplicate', [WorkOrderController::class, 'duplicate']);

    // ── Nested: Parts & Time Logs ─────────────────────────────────────────────
    Route::apiResource('work-orders.parts',     WorkOrderPartsController::class)->shallow();
    Route::get('/work-orders/{workOrder}/parts/check-stock', [WorkOrderPartsController::class, 'checkStock']);

    Route::apiResource('work-orders.time-logs', TimeLogController::class)->shallow();

    // ── Worksheets ────────────────────────────────────────────────────────────
    Route::prefix('worksheets')->group(function () {
        Route::apiResource('templates', WorksheetTemplateController::class)
            ->parameters(['templates' => 'template']);
    });

    Route::get('/work-orders/{workOrder}/worksheet',      [WorksheetResponseController::class, 'show']);
    Route::post('/work-orders/{workOrder}/worksheet',     [WorksheetResponseController::class, 'save']);
    Route::post('/work-orders/{workOrder}/worksheet/sign', [WorksheetResponseController::class, 'sign']);
    Route::get('/work-orders/{workOrder}/worksheet/pdf',  [WorksheetResponseController::class, 'pdf']);

    // ── Technicians ───────────────────────────────────────────────────────────
    Route::get('/technicians/non-technicians', [TechnicianController::class, 'nonTechnicians']);
    Route::get('/technicians/{technician}/stats', [TechnicianController::class, 'stats']);
    Route::apiResource('technicians', TechnicianController::class);

    // ── Teams ─────────────────────────────────────────────────────────────────
    Route::apiResource('teams', TeamController::class);

    // ── Config ────────────────────────────────────────────────────────────────
    Route::prefix('config')->group(function () {
        Route::get('/work-order-types',           [ConfigController::class, 'indexTypes']);
        Route::post('/work-order-types',          [ConfigController::class, 'storeType']);
        Route::put('/work-order-types/{type}',    [ConfigController::class, 'updateType']);
        Route::delete('/work-order-types/{type}', [ConfigController::class, 'destroyType']);
    });

    // ── Analytics ─────────────────────────────────────────────────────────────
    Route::prefix('analytics')->group(function () {
        Route::get('/dashboard',               [AnalyticsController::class, 'dashboard']);
        Route::get('/technician-performance',  [AnalyticsController::class, 'technicianPerformance']);
        Route::get('/revenue',                 [AnalyticsController::class, 'revenue']);
        Route::get('/jobs',                    [AnalyticsController::class, 'jobsAnalysis']);
        Route::get('/jobs/export',             [AnalyticsController::class, 'exportCsv']);
    });
});
