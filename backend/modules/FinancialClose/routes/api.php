<?php

use Illuminate\Support\Facades\Route;
use Modules\FinancialClose\Http\Controllers\CloseCycleController;
use Modules\FinancialClose\Http\Controllers\TaskController;
use Modules\FinancialClose\Http\Controllers\EscalationController;
use Modules\FinancialClose\Http\Controllers\FindingController;
use Modules\FinancialClose\Http\Controllers\ReadinessController;
use Modules\FinancialClose\Http\Controllers\ForecastController;
use Modules\FinancialClose\Http\Controllers\ChecklistTemplateController;
use Modules\FinancialClose\Http\Controllers\SignoffController;
use Modules\FinancialClose\Http\Controllers\AuditTrailController;

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
Route::post('/cycles/{cycleId}/signoff',         [CloseCycleController::class, 'signoff']);
Route::post('/cycles/{cycleId}/revoke-signoff',  [CloseCycleController::class, 'revokeSignoff']);
Route::get('/cycles/{cycleId}/readiness',        [CloseCycleController::class, 'readiness']);
Route::post('/cycles/{cycleId}/generate-tasks', [CloseCycleController::class, 'generateTasks']);
Route::post('/cycles/reopen-period',           [CloseCycleController::class, 'reopenPeriod']);

// ─── Tasks ───────────────────────────────────────────────────────────────────
Route::get('/users',                              [TaskController::class, 'users']);
Route::get('/cycles/{cycleId}/tasks',           [TaskController::class, 'index']);
Route::get('/tasks/my',                         [TaskController::class, 'myTasks']);
Route::get('/tasks/review-queue',               [TaskController::class, 'reviewQueue']);
Route::get('/tasks/{taskId}',                   [TaskController::class, 'show']);
Route::post('/tasks/{taskId}/start',            [TaskController::class, 'start']);
Route::post('/tasks/{taskId}/submit',           [TaskController::class, 'submit']);
Route::post('/tasks/{taskId}/complete',         [TaskController::class, 'complete']);
Route::post('/tasks/{taskId}/return',           [TaskController::class, 'returnTask']);
Route::post('/tasks/{taskId}/block',            [TaskController::class, 'block']);
Route::post('/tasks/{taskId}/assign',           [TaskController::class, 'assign']);
Route::post('/tasks/{taskId}/reassign',         [TaskController::class, 'reassign']);
Route::get('/tasks/{taskId}/assignments',       [TaskController::class, 'assignmentHistory']);
Route::post('/tasks/{taskId}/checklist',        [TaskController::class, 'saveChecklist']);
Route::post('/tasks/{taskId}/evidence',         [TaskController::class, 'uploadEvidence']);
Route::get('/tasks/{taskId}/evidence',          [TaskController::class, 'listEvidence']);
Route::post('/tasks/{taskId}/link-document',    [TaskController::class, 'linkDocument']);
Route::delete('/tasks/{taskId}/link-document',  [TaskController::class, 'unlinkDocument']);

// ─── Escalations ─────────────────────────────────────────────────────────────
Route::get('/tasks/{taskId}/escalations',               [EscalationController::class, 'index']);
Route::post('/tasks/{taskId}/escalations',              [EscalationController::class, 'store']);
Route::post('/escalations/{escalationId}/resolve',      [EscalationController::class, 'resolve']);

// ─── AI Findings ─────────────────────────────────────────────────────────────
Route::get('/findings',                                 [FindingController::class, 'listAll']);
Route::post('/findings/refresh',                        [FindingController::class, 'refreshFindings']);
Route::get('/reviews/{reviewId}/findings',              [FindingController::class, 'index']);
Route::post('/reviews/{reviewId}/findings',             [FindingController::class, 'store']);
Route::post('/findings/{findingId}/accept',             [FindingController::class, 'accept']);
Route::post('/findings/{findingId}/edit',               [FindingController::class, 'edit']);
Route::post('/findings/{findingId}/dismiss',            [FindingController::class, 'dismiss']);
Route::post('/findings/{findingId}/escalate',           [FindingController::class, 'escalate']);

// ─── Readiness ───────────────────────────────────────────────────────────────
Route::get('/readiness/{entityId}/{periodId}',          [ReadinessController::class, 'show']);
Route::post('/readiness/{entityId}/{periodId}/refresh', [ReadinessController::class, 'refresh']);

// ─── Checklist Templates ─────────────────────────────────────────────────────
Route::get('/checklist-templates',                      [ChecklistTemplateController::class, 'index']);
Route::post('/checklist-templates',                     [ChecklistTemplateController::class, 'store']);
Route::get('/checklist-templates/{templateId}',         [ChecklistTemplateController::class, 'show']);
Route::put('/checklist-templates/{templateId}',         [ChecklistTemplateController::class, 'update']);
Route::delete('/checklist-templates/{templateId}',      [ChecklistTemplateController::class, 'destroy']);
Route::post('/checklist-templates/{templateId}/activate', [ChecklistTemplateController::class, 'activate']);

// ─── Sign-offs ───────────────────────────────────────────────────────────────
Route::get('/reviews/{reviewId}/signoffs',              [SignoffController::class, 'index']);
Route::post('/reviews/{reviewId}/signoffs',             [SignoffController::class, 'store']);
Route::post('/signoffs/{signoffId}/revoke',             [SignoffController::class, 'revoke']);

// ─── Forecast ────────────────────────────────────────────────────────────────
Route::get('/cycles/{cycleId}/forecast',                [ForecastController::class, 'show']);
Route::get('/forecast/{entityId}/executive',            [ForecastController::class, 'executive']);

// ─── Audit Trail ─────────────────────────────────────────────────────────────
Route::get('/audit-trail',                              [AuditTrailController::class, 'index']);
Route::get('/audit-trail/actions',                      [AuditTrailController::class, 'actions']);
Route::get('/audit-trail/stats',                        [AuditTrailController::class, 'stats']);
