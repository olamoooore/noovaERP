<?php

use Illuminate\Support\Facades\Route;
use Modules\FinancialClose\Http\Controllers\CloseCycleController;
use Modules\FinancialClose\Http\Controllers\TaskController;
use Modules\FinancialClose\Http\Controllers\EscalationController;
use Modules\FinancialClose\Http\Controllers\FindingController;
use Modules\FinancialClose\Http\Controllers\ReadinessController;
use Modules\FinancialClose\Http\Controllers\ForecastController;

/*
|--------------------------------------------------------------------------
| FinancialClose Module API Routes
| Prefix: /api/v1/close  (set in FinancialCloseServiceProvider)
| Middleware: api, tenant
|--------------------------------------------------------------------------
*/

// ─── Close Cycles ───────────────────────────────────────────────────────────
Route::get('/cycles',                           [CloseCycleController::class, 'index']);
Route::post('/cycles',                          [CloseCycleController::class, 'store']);
Route::post('/cycles/{cycleId}/open',           [CloseCycleController::class, 'open']);
Route::post('/cycles/{cycleId}/close',          [CloseCycleController::class, 'close']);

// ─── Tasks ───────────────────────────────────────────────────────────────────
Route::get('/cycles/{cycleId}/tasks',           [TaskController::class, 'index']);
Route::get('/tasks/my',                         [TaskController::class, 'myTasks']);
Route::get('/tasks/review-queue',               [TaskController::class, 'reviewQueue']);
Route::post('/tasks/{taskId}/start',            [TaskController::class, 'start']);
Route::post('/tasks/{taskId}/submit',           [TaskController::class, 'submit']);
Route::post('/tasks/{taskId}/complete',         [TaskController::class, 'complete']);
Route::post('/tasks/{taskId}/return',           [TaskController::class, 'returnTask']);
Route::post('/tasks/{taskId}/block',            [TaskController::class, 'block']);

// ─── Escalations ─────────────────────────────────────────────────────────────
Route::get('/tasks/{taskId}/escalations',               [EscalationController::class, 'index']);
Route::post('/tasks/{taskId}/escalations',              [EscalationController::class, 'store']);
Route::post('/escalations/{escalationId}/resolve',      [EscalationController::class, 'resolve']);

// ─── AI Findings ─────────────────────────────────────────────────────────────
Route::get('/reviews/{reviewId}/findings',              [FindingController::class, 'index']);
Route::post('/reviews/{reviewId}/findings',             [FindingController::class, 'store']);
Route::post('/findings/{findingId}/accept',             [FindingController::class, 'accept']);
Route::post('/findings/{findingId}/edit',               [FindingController::class, 'edit']);
Route::post('/findings/{findingId}/dismiss',            [FindingController::class, 'dismiss']);
Route::post('/findings/{findingId}/escalate',           [FindingController::class, 'escalate']);

// ─── Readiness ───────────────────────────────────────────────────────────────
Route::get('/readiness/{entityId}/{periodId}',          [ReadinessController::class, 'show']);
Route::post('/readiness/{entityId}/{periodId}/refresh', [ReadinessController::class, 'refresh']);

// ─── Forecast ────────────────────────────────────────────────────────────────
Route::get('/cycles/{cycleId}/forecast',                [ForecastController::class, 'show']);
Route::get('/forecast/{entityId}/executive',            [ForecastController::class, 'executive']);
