<?php

namespace Modules\FinancialClose\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Services\FinancialCloseReadinessService;
use Modules\FinancialClose\Models\JobRunHistory;

/**
 * Async job — refreshes the readiness snapshot for an entity/period.
 * Safe to retry: snapshot creation is append-only; callers always read the latest.
 */
class RefreshReadinessSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string  $tenantId,
        public readonly string  $entityId,
        public readonly string  $periodId,
        public readonly ?string $cycleId = null,
        public readonly string  $correlationId = '',
    ) {}

    public function handle(FinancialCloseReadinessService $readinessService): void
    {
        $startedAt = now();

        Log::info('FinancialClose: RefreshReadinessSnapshotJob started', [
            'tenant_id'      => $this->tenantId,
            'entity_id'      => $this->entityId,
            'period_id'      => $this->periodId,
            'correlation_id' => $this->correlationId,
        ]);

        $snapshot = $readinessService->generateSnapshot(
            $this->tenantId,
            $this->entityId,
            $this->periodId,
            $this->cycleId,
        );

        JobRunHistory::create([
            'tenant_id'      => $this->tenantId,
            'job_name'       => 'RefreshReadinessSnapshotJob',
            'reference_id'   => $this->entityId,
            'started_at'     => $startedAt,
            'finished_at'    => now(),
            'result'         => ['readiness_score' => $snapshot?->readiness_score],
            'correlation_id' => $this->correlationId,
        ]);
    }
}
