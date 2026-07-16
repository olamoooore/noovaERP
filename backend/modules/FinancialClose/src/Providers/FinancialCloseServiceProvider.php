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
        // Routes — scoped under /api/v1/close with full auth + tenant middleware
        Route::prefix('api/v1/close')
            ->middleware([
                'api',
                'auth:sanctum',
                \App\Http\Middleware\SetTenantFromUserPreference::class,
                \App\Http\Middleware\EnsureTenantSearchPath::class,
                \App\Http\Middleware\SyncTenantUserFromCentralAuth::class,
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ])
            ->group(__DIR__.'/../../routes/api.php');

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
