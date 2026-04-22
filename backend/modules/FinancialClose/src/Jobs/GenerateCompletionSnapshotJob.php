<?php

namespace Modules\FinancialClose\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Services\FinancialCloseCompletionService;
use Modules\FinancialClose\Models\JobRunHistory;

/**
 * Async job — appends a completion snapshot for a cycle.
 * Safe to retry: snapshots are append-only, latest is always read by callers.
 */
class GenerateCompletionSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $cycleId,
        public readonly string $correlationId = '',
    ) {}

    public function handle(FinancialCloseCompletionService $completionService): void
    {
        $startedAt = now();

        Log::info('FinancialClose: GenerateCompletionSnapshotJob started', [
            'tenant_id'      => $this->tenantId,
            'cycle_id'       => $this->cycleId,
            'correlation_id' => $this->correlationId,
        ]);

        $snapshot = $completionService->generateSnapshot($this->tenantId, $this->cycleId);

        JobRunHistory::create([
            'tenant_id'      => $this->tenantId,
            'job_name'       => 'GenerateCompletionSnapshotJob',
            'reference_id'   => $this->cycleId,
            'started_at'     => $startedAt,
            'finished_at'    => now(),
            'result'         => ['completion_percentage' => $snapshot?->completion_percentage],
            'correlation_id' => $this->correlationId,
        ]);
    }
}
