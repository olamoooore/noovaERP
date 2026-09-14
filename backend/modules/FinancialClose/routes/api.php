<?php

use Illuminate\Support\Facades\Route;
use Modules\FinancialClose\Http\Controllers\AuditTrailController;
use Modules\FinancialClose\Http\Controllers\ChecklistTemplateController;
use Modules\FinancialClose\Http\Controllers\CloseCycleController;
use Modules\FinancialClose\Http\Controllers\EscalationController;
use Modules\FinancialClose\Http\Controllers\FindingController;
use Modules\FinancialClose\Http\Controllers\ForecastController;
use Modules\FinancialClose\Http\Controllers\ReadinessController;
use Modules\FinancialClose\Http\Controllers\SignoffController;
use Modules\FinancialClose\Http\Controllers\TaskController;

/*
|--------------------------------------------------------------------------
| FinancialClose Module API Routes
|
| These routes are loaded twice — once here at the top-level tenant route
| group (auth + tenant middleware inherited), and once via the
| FinancialCloseServiceProvider under /api/v1/close (same routes, same
| controllers). Both copies MUST carry the same write/read permission
| split so duplicate unprefixed routes can't bypass permission (#38).
|--------------------------------------------------------------------------
*/

// ── Reads: accounting.close.read ────────────────────────────────────────────
Route::middleware([\App\Http\Middleware\RequirePermission::using('accounting.close.read')])->group(function () {
    // ─── Close Cycles ───────────────────────────────────────────────────────
    Route::get('/cycles', [CloseCycleController::class, 'index']);
    Route::get('/cycles/{cycleId}/signoff-check', [CloseCycleController::class, 'signoffCheck']);
    Route::get('/cycles/{cycleId}/readiness', [CloseCycleController::class, 'readiness']);
    Route::get('/cycles/{cycleId}/tasks', [TaskController::class, 'index']);
    Route::get('/cycles/{cycleId}/forecast', [ForecastController::class, 'show']);

    // ─── Tasks ──────────────────────────────────────────────────────────────
    Route::get('/users', [TaskController::class, 'users']);
    Route::get('/tasks/my', [TaskController::class, 'myTasks']);
    Route::get('/tasks/review-queue', [TaskController::class, 'reviewQueue']);
    Route::get('/tasks/{taskId}', [TaskController::class, 'show']);
    Route::get('/tasks/{taskId}/blockers', [TaskController::class, 'checkBlockers']);
    Route::get('/tasks/{taskId}/assignments', [TaskController::class, 'assignmentHistory']);
    Route::get('/tasks/{taskId}/evidence', [TaskController::class, 'listEvidence']);
    Route::get('/tasks/{taskId}/evidence/{evidenceId}/download', [TaskController::class, 'downloadEvidence']);
    Route::get('/tasks/{taskId}/evidence/audit-trail', [TaskController::class, 'evidenceAuditTrail']);

    // ─── Escalations ────────────────────────────────────────────────────────
    Route::get('/tasks/{taskId}/escalations', [EscalationController::class, 'index']);

    // ─── AI Findings ────────────────────────────────────────────────────────
    Route::get('/findings', [FindingController::class, 'listAll']);
    Route::get('/reviews/{reviewId}/findings', [FindingController::class, 'index']);

    // ─── Readiness ──────────────────────────────────────────────────────────
    Route::get('/readiness/entities', [ReadinessController::class, 'entities']);
    Route::get('/readiness/{entityId}/{periodId}', [ReadinessController::class, 'show']);

    // ─── Checklist Templates ────────────────────────────────────────────────
    Route::get('/checklist-templates', [ChecklistTemplateController::class, 'index']);
    Route::get('/checklist-templates/{templateId}', [ChecklistTemplateController::class, 'show']);

    // ─── Sign-offs ──────────────────────────────────────────────────────────
    Route::get('/reviews/{reviewId}/signoffs', [SignoffController::class, 'index']);

    // ─── Forecast ───────────────────────────────────────────────────────────
    Route::get('/forecast/{entityId}/executive', [ForecastController::class, 'executive']);

    // ─── Audit Trail ────────────────────────────────────────────────────────
    Route::get('/audit-trail', [AuditTrailController::class, 'index']);
    Route::get('/audit-trail/actions', [AuditTrailController::class, 'actions']);
    Route::get('/audit-trail/stats', [AuditTrailController::class, 'stats']);
});

// ── Cycle management writes: accounting.close.manage ────────────────────────
Route::middleware([\App\Http\Middleware\RequirePermission::using('accounting.close.manage')])->group(function () {
    Route::post('/cycles', [CloseCycleController::class, 'store']);
    Route::post('/cycles/{cycleId}/open', [CloseCycleController::class, 'open']);
    Route::post('/cycles/{cycleId}/generate-tasks', [CloseCycleController::class, 'generateTasks']);
});

// ── Sign-off writes: accounting.close.signoff (SoD: preparer ≠ approver) ──
Route::middleware([\App\Http\Middleware\RequirePermission::using('accounting.close.signoff')])->group(function () {
    Route::post('/cycles/{cycleId}/signoff', [CloseCycleController::class, 'signoff']);
    Route::post('/cycles/{cycleId}/revoke-signoff', [CloseCycleController::class, 'revokeSignoff']);
    Route::post('/reviews/{reviewId}/signoffs', [SignoffController::class, 'store']);
    Route::post('/signoffs/{signoffId}/revoke', [SignoffController::class, 'revoke']);
});

// ── Period close / reopen / archive: accounting.close.admin ────────────────
Route::middleware([\App\Http\Middleware\RequirePermission::using('accounting.close.admin')])->group(function () {
    Route::post('/cycles/{cycleId}/close', [CloseCycleController::class, 'close']);
    Route::post('/cycles/{cycleId}/archive', [CloseCycleController::class, 'archive']);
    Route::post('/cycles/reopen-period', [CloseCycleController::class, 'reopenPeriod']);
});

// ── Task writes: accounting.close.task_write ────────────────────────────────
Route::middleware([\App\Http\Middleware\RequirePermission::using('accounting.close.task_write')])->group(function () {
    Route::post('/tasks/{taskId}/start', [TaskController::class, 'start']);
    Route::post('/tasks/{taskId}/submit', [TaskController::class, 'submit']);
    Route::post('/tasks/{taskId}/complete', [TaskController::class, 'complete']);
    Route::post('/tasks/{taskId}/return', [TaskController::class, 'returnTask']);
    Route::post('/tasks/{taskId}/block', [TaskController::class, 'block']);
    Route::post('/tasks/{taskId}/assign', [TaskController::class, 'assign']);
    Route::post('/tasks/{taskId}/reassign', [TaskController::class, 'reassign']);
    Route::post('/tasks/{taskId}/checklist', [TaskController::class, 'saveChecklist']);
    Route::post('/tasks/{taskId}/evidence', [TaskController::class, 'uploadEvidence']);
    Route::post('/tasks/{taskId}/link-document', [TaskController::class, 'linkDocument']);
    Route::delete('/tasks/{taskId}/link-document', [TaskController::class, 'unlinkDocument']);
    Route::post('/readiness/{entityId}/{periodId}/refresh', [ReadinessController::class, 'refresh']);
});

// ── Finding writes: accounting.close.finding_write ─────────────────────────
Route::middleware([\App\Http\Middleware\RequirePermission::using('accounting.close.finding_write')])->group(function () {
    Route::post('/findings/refresh', [FindingController::class, 'refreshFindings']);
    Route::post('/reviews/{reviewId}/findings', [FindingController::class, 'store']);
    Route::post('/findings/{findingId}/accept', [FindingController::class, 'accept']);
    Route::post('/findings/{findingId}/edit', [FindingController::class, 'edit']);
    Route::post('/findings/{findingId}/dismiss', [FindingController::class, 'dismiss']);
    Route::post('/findings/{findingId}/escalate', [FindingController::class, 'escalate']);
});

// ── Escalation writes: accounting.close.escalation_write ──────────────────
Route::middleware([\App\Http\Middleware\RequirePermission::using('accounting.close.escalation_write')])->group(function () {
    Route::post('/tasks/{taskId}/escalations', [EscalationController::class, 'store']);
    Route::post('/escalations/{escalationId}/resolve', [EscalationController::class, 'resolve']);
    Route::post('/escalations/{escalationId}/return', [EscalationController::class, 'return']);
    Route::post('/escalations/{escalationId}/approve', [EscalationController::class, 'approve']);
});

// ── Checklist template writes: accounting.close.template_write ─────────────
Route::middleware([\App\Http\Middleware\RequirePermission::using('accounting.close.template_write')])->group(function () {
    Route::post('/checklist-templates', [ChecklistTemplateController::class, 'store']);
    Route::put('/checklist-templates/{templateId}', [ChecklistTemplateController::class, 'update']);
    Route::delete('/checklist-templates/{templateId}', [ChecklistTemplateController::class, 'destroy']);
    Route::post('/checklist-templates/{templateId}/activate', [ChecklistTemplateController::class, 'activate']);
});
