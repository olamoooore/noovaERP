<?php

use Illuminate\Support\Facades\Route;
use Modules\FieldService\Http\Controllers\AnalyticsController;
use Modules\FieldService\Http\Controllers\ConfigController;
use Modules\FieldService\Http\Controllers\TeamController;
use Modules\FieldService\Http\Controllers\TechnicianController;
use Modules\FieldService\Http\Controllers\TimeLogController;
use Modules\FieldService\Http\Controllers\WorkOrderController;
use Modules\FieldService\Http\Controllers\WorkOrderPartsController;
use Modules\FieldService\Http\Controllers\WorksheetResponseController;
use Modules\FieldService\Http\Controllers\WorksheetTemplateController;

Route::middleware(['auth:sanctum', 'entitlement:field_service'])->prefix('field-service')->group(function () {

    // ── Work Orders ───────────────────────────────────────────────────────────
    Route::get('/work-orders/calendar', [WorkOrderController::class, 'calendar']);
    Route::get('/work-orders/gantt', [WorkOrderController::class, 'gantt']);
    Route::get('/work-orders/map', [WorkOrderController::class, 'map']);

    Route::apiResource('work-orders', WorkOrderController::class)->parameters([
        'work-orders' => 'work_order_id',
    ]);

    Route::post('/work-orders/{work_order_id}/status', [WorkOrderController::class, 'updateStatus']);
    Route::post('/work-orders/{work_order_id}/assign', [WorkOrderController::class, 'assign']);
    Route::post('/work-orders/{work_order_id}/note', [WorkOrderController::class, 'addNote']);
    Route::post('/work-orders/{work_order_id}/invoice', [WorkOrderController::class, 'generateInvoice']);
    Route::post('/work-orders/{work_order_id}/rating', [WorkOrderController::class, 'submitRating']);
    Route::post('/work-orders/{work_order_id}/duplicate', [WorkOrderController::class, 'duplicate']);

    // ── Nested: Parts & Time Logs ─────────────────────────────────────────────
    Route::apiResource('work-orders.parts', WorkOrderPartsController::class)->parameters([
        'work-orders' => 'work_order_id',
        'parts' => 'part_id',
    ]);
    Route::get('/work-orders/{work_order_id}/parts/check-stock', [WorkOrderPartsController::class, 'checkStock']);

    Route::apiResource('work-orders.time-logs', TimeLogController::class)->parameters([
        'work-orders' => 'work_order_id',
        'time-logs' => 'time_log_id',
    ]);

    // ── Worksheets ────────────────────────────────────────────────────────────
    Route::prefix('worksheets')->group(function () {
        Route::apiResource('templates', WorksheetTemplateController::class)
            ->parameters(['templates' => 'template_id']);
    });

    Route::get('/work-orders/{work_order_id}/worksheet', [WorksheetResponseController::class, 'show']);
    Route::post('/work-orders/{work_order_id}/worksheet', [WorksheetResponseController::class, 'save']);
    Route::post('/work-orders/{work_order_id}/worksheet/sign', [WorksheetResponseController::class, 'sign']);
    Route::get('/work-orders/{work_order_id}/worksheet/pdf', [WorksheetResponseController::class, 'pdf']);

    // ── Technicians ───────────────────────────────────────────────────────────
    Route::get('/technicians/non-technicians', [TechnicianController::class, 'nonTechnicians']);
    Route::get('/technicians/{technician_id}/stats', [TechnicianController::class, 'stats']);
    Route::apiResource('technicians', TechnicianController::class)->parameters([
        'technicians' => 'technician_id',
    ]);

    // ── Teams ─────────────────────────────────────────────────────────────────
    Route::apiResource('teams', TeamController::class)->parameters([
        'teams' => 'team_id',
    ]);

    // ── Config ────────────────────────────────────────────────────────────────
    Route::prefix('config')->group(function () {
        Route::get('/work-order-types', [ConfigController::class, 'indexTypes']);
        Route::post('/work-order-types', [ConfigController::class, 'storeType']);
        Route::put('/work-order-types/{type}', [ConfigController::class, 'updateType']);
        Route::delete('/work-order-types/{type}', [ConfigController::class, 'destroyType']);
    });

    // ── Analytics ─────────────────────────────────────────────────────────────
    Route::prefix('analytics')->group(function () {
        Route::get('/dashboard', [AnalyticsController::class, 'dashboard']);
        Route::get('/technician-performance', [AnalyticsController::class, 'technicianPerformance']);
        Route::get('/revenue', [AnalyticsController::class, 'revenue']);
        Route::get('/jobs', [AnalyticsController::class, 'jobsAnalysis']);
        Route::get('/jobs/export', [AnalyticsController::class, 'exportCsv']);
    });
});
