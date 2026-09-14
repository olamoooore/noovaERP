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
                // HR38-01: SINGLE authoritative permission-registration path.
                //
                // routes/api.php already carries explicit per-action RequirePermission
                // groups (accounting.close.read for GETs; manage / signoff / admin /
                // task_write / finding_write / escalation_write / template_write for the
                // corresponding writes). It is required here directly under the
                // auth + tenant group — with NO blanket accounting.close.read wrapper and
                // NO duplicate write re-registration.
                //
                // Previously this provider (a) wrapped the require in an outer
                // accounting.close.read group and (b) re-registered every write route
                // again with its stronger permission, so the effective authority of a
                // /api/v1/close/* write depended on Laravel route-replacement ordering
                // (the later duplicate silently overriding the read-wrapped original).
                // Authority must not depend on registration ordering: each route now
                // obtains its permission from exactly one registration in routes/api.php.
                require __DIR__.'/../../routes/api.php';
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
