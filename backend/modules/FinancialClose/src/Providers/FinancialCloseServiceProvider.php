<?php

namespace Modules\FinancialClose\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\FinancialClose\Console\Commands\RunAiAnalysisCommand;
use Modules\FinancialClose\Events\FinancialCloseCycleOpened;
use Modules\FinancialClose\Events\FinancialCloseTaskCompleted;
use Modules\FinancialClose\Listeners\TriggerCompletionSnapshotOnTaskCompletion;
use Modules\FinancialClose\Listeners\TriggerReadinessRefreshOnTaskCompletion;
use Modules\FinancialClose\Listeners\TriggerTaskGenerationOnCycleOpened;

class FinancialCloseServiceProvider extends ServiceProvider
{
    protected array $commands = [
        RunAiAnalysisCommand::class,
    ];

    public function boot(): void
    {
        // Routes — scoped under /api/v1/close with full auth + tenant + permission middleware
        Route::prefix('api/v1/close')
            ->middleware([
                'api',
                'auth:sanctum',
                \App\Http\Middleware\SetTenantFromUserPreference::class,
                \App\Http\Middleware\EnsureTenantSearchPath::class,
                \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ])
            ->group(function () {
                // ── Read-only routes (any authenticated user with tenant access) ──
                Route::middleware([\App\Http\Middleware\RequirePermission::using('accounting.close.read')])->group(function () {
                    require __DIR__.'/../../routes/api.php';
                });

                // ── Write routes (require specific permissions) ──
                // Cycle management requires accounting.close.manage
                Route::post('/cycles', [\Modules\FinancialClose\Http\Controllers\CloseCycleController::class, 'store'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.manage'));
                Route::post('/cycles/{cycleId}/open', [\Modules\FinancialClose\Http\Controllers\CloseCycleController::class, 'open'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.manage'));
                Route::post('/cycles/{cycleId}/generate-tasks', [\Modules\FinancialClose\Http\Controllers\CloseCycleController::class, 'generateTasks'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.manage'));

                // Sign-off requires accounting.close.signoff (SoD: preparer ≠ approver)
                Route::post('/cycles/{cycleId}/signoff', [\Modules\FinancialClose\Http\Controllers\CloseCycleController::class, 'signoff'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.signoff'));
                Route::post('/cycles/{cycleId}/revoke-signoff', [\Modules\FinancialClose\Http\Controllers\CloseCycleController::class, 'revokeSignoff'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.signoff'));

                // Close (final) requires accounting.close.admin
                Route::post('/cycles/{cycleId}/close', [\Modules\FinancialClose\Http\Controllers\CloseCycleController::class, 'close'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.admin'));

                // Period reopen requires accounting.close.admin
                Route::post('/cycles/reopen-period', [\Modules\FinancialClose\Http\Controllers\CloseCycleController::class, 'reopenPeriod'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.admin'));

                // Task write operations require accounting.close.task_write
                Route::post('/tasks/{taskId}/start', [\Modules\FinancialClose\Http\Controllers\TaskController::class, 'start'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.task_write'));
                Route::post('/tasks/{taskId}/submit', [\Modules\FinancialClose\Http\Controllers\TaskController::class, 'submit'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.task_write'));
                Route::post('/tasks/{taskId}/complete', [\Modules\FinancialClose\Http\Controllers\TaskController::class, 'complete'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.task_write'));
                Route::post('/tasks/{taskId}/return', [\Modules\FinancialClose\Http\Controllers\TaskController::class, 'returnTask'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.task_write'));
                Route::post('/tasks/{taskId}/block', [\Modules\FinancialClose\Http\Controllers\TaskController::class, 'block'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.task_write'));
                Route::post('/tasks/{taskId}/assign', [\Modules\FinancialClose\Http\Controllers\TaskController::class, 'assign'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.task_write'));
                Route::post('/tasks/{taskId}/reassign', [\Modules\FinancialClose\Http\Controllers\TaskController::class, 'reassign'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.task_write'));

                // Finding management requires accounting.close.finding_write
                Route::post('/findings/refresh', [\Modules\FinancialClose\Http\Controllers\FindingController::class, 'refreshFindings'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.finding_write'));
                Route::post('/reviews/{reviewId}/findings', [\Modules\FinancialClose\Http\Controllers\FindingController::class, 'store'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.finding_write'));
                Route::post('/findings/{findingId}/accept', [\Modules\FinancialClose\Http\Controllers\FindingController::class, 'accept'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.finding_write'));
                Route::post('/findings/{findingId}/edit', [\Modules\FinancialClose\Http\Controllers\FindingController::class, 'edit'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.finding_write'));
                Route::post('/findings/{findingId}/dismiss', [\Modules\FinancialClose\Http\Controllers\FindingController::class, 'dismiss'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.finding_write'));
                Route::post('/findings/{findingId}/escalate', [\Modules\FinancialClose\Http\Controllers\FindingController::class, 'escalate'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.finding_write'));

                // Escalation management requires accounting.close.escalation_write
                Route::post('/tasks/{taskId}/escalations', [\Modules\FinancialClose\Http\Controllers\EscalationController::class, 'store'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.escalation_write'));
                Route::post('/escalations/{escalationId}/resolve', [\Modules\FinancialClose\Http\Controllers\EscalationController::class, 'resolve'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.escalation_write'));
                Route::post('/escalations/{escalationId}/return', [\Modules\FinancialClose\Http\Controllers\EscalationController::class, 'return'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.escalation_write'));
                Route::post('/escalations/{escalationId}/approve', [\Modules\FinancialClose\Http\Controllers\EscalationController::class, 'approve'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.escalation_write'));

                // Checklist template management requires accounting.close.template_write
                Route::post('/checklist-templates', [\Modules\FinancialClose\Http\Controllers\ChecklistTemplateController::class, 'store'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.template_write'));
                Route::put('/checklist-templates/{templateId}', [\Modules\FinancialClose\Http\Controllers\ChecklistTemplateController::class, 'update'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.template_write'));
                Route::delete('/checklist-templates/{templateId}', [\Modules\FinancialClose\Http\Controllers\ChecklistTemplateController::class, 'destroy'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.template_write'));
                Route::post('/checklist-templates/{templateId}/activate', [\Modules\FinancialClose\Http\Controllers\ChecklistTemplateController::class, 'activate'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.template_write'));

                // Signoff management requires accounting.close.signoff
                Route::post('/reviews/{reviewId}/signoffs', [\Modules\FinancialClose\Http\Controllers\SignoffController::class, 'store'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.signoff'));
                Route::post('/signoffs/{signoffId}/revoke', [\Modules\FinancialClose\Http\Controllers\SignoffController::class, 'revoke'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.signoff'));

                // ── P0-2: Task mutation routes (checklist, evidence, link-document) require task_write ──
                Route::post('/tasks/{taskId}/checklist', [\Modules\FinancialClose\Http\Controllers\TaskController::class, 'saveChecklist'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.task_write'));
                Route::post('/tasks/{taskId}/evidence', [\Modules\FinancialClose\Http\Controllers\TaskController::class, 'uploadEvidence'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.task_write'));
                Route::post('/tasks/{taskId}/link-document', [\Modules\FinancialClose\Http\Controllers\TaskController::class, 'linkDocument'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.task_write'));
                Route::delete('/tasks/{taskId}/link-document', [\Modules\FinancialClose\Http\Controllers\TaskController::class, 'unlinkDocument'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.task_write'));

                // ── P0-2: Readiness refresh requires task_write ──
                Route::post('/readiness/{entityId}/{periodId}/refresh', [\Modules\FinancialClose\Http\Controllers\ReadinessController::class, 'refresh'])
                    ->middleware(\App\Http\Middleware\RequirePermission::using('accounting.close.task_write'));
            });

        // Event → Listener bindings
        Event::listen(FinancialCloseTaskCompleted::class, TriggerReadinessRefreshOnTaskCompletion::class);
        Event::listen(FinancialCloseTaskCompleted::class, TriggerCompletionSnapshotOnTaskCompletion::class);
        Event::listen(FinancialCloseCycleOpened::class, TriggerTaskGenerationOnCycleOpened::class);
    }

    public function register(): void
    {
        $this->commands($this->commands);
    }
}
