<?php

namespace Modules\FinancialClose\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Services\FinancialCloseChecklistService;
use Modules\FinancialClose\Models\CloseCycle;
use Modules\FinancialClose\Models\JobRunHistory;

/**
 * Dispatched when a cycle opens — expands the active checklist template into tasks.
 * Idempotency: FinancialCloseChecklistService::expandTemplateIntoTasks checks template status.
 * If no template_id on cycle, logs a warning and exits cleanly.
 */
class GenerateTasksFromChecklistJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $cycleId,
        public readonly string $correlationId = '',
    ) {}

    public function handle(FinancialCloseChecklistService $checklistService): void
    {
        $startedAt = now();

        $cycle = CloseCycle::where('tenant_id', $this->tenantId)
            ->where('id', $this->cycleId)
            ->first();

        if (!$cycle) {
            Log::warning('FinancialClose: GenerateTasksFromChecklistJob — cycle not found', [
                'cycle_id' => $this->cycleId,
            ]);
            return;
        }

        $templateId = $cycle->template_id ?? null;

        if (!$templateId) {
            Log::warning('FinancialClose: GenerateTasksFromChecklistJob — no template_id on cycle, skipping task generation', [
                'cycle_id' => $this->cycleId,
            ]);
            return;
        }

        $result = $checklistService->expandTemplateIntoTasks($this->tenantId, $this->cycleId, $templateId);

        Log::info('FinancialClose: GenerateTasksFromChecklistJob complete', [
            'tenant_id'      => $this->tenantId,
            'cycle_id'       => $this->cycleId,
            'template_id'    => $templateId,
            'result'         => $result,
            'correlation_id' => $this->correlationId,
        ]);

        JobRunHistory::create([
            'tenant_id'      => $this->tenantId,
            'job_name'       => 'GenerateTasksFromChecklistJob',
            'reference_id'   => $this->cycleId,
            'started_at'     => $startedAt,
            'finished_at'    => now(),
            'result'         => ['success' => $result, 'template_id' => $templateId],
            'correlation_id' => $this->correlationId,
        ]);
    }
}
