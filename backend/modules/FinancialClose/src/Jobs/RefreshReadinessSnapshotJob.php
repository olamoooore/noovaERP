<?php

namespace Modules\FinancialClose\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\JobRunHistory;
use Modules\FinancialClose\Services\FinancialCloseAiAnalysisService;
use Modules\FinancialClose\Services\FinancialCloseReadinessService;

/**
 * Async job — refreshes the readiness snapshot for an entity/period.
 * Safe to retry: snapshot creation is append-only; callers always read the latest.
 */
class RefreshReadinessSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $entityId,
        public readonly string $periodId,
        public readonly ?string $cycleId = null,
        public readonly string $correlationId = '',
    ) {}

    public function handle(FinancialCloseReadinessService $readinessService, FinancialCloseAiAnalysisService $aiAnalysisService): void
    {
        tenancy()->initialize($this->tenantId);

        $startedAt = now();

        Log::info('FinancialClose: RefreshReadinessSnapshotJob started', [
            'tenant_id' => $this->tenantId,
            'entity_id' => $this->entityId,
            'period_id' => $this->periodId,
            'correlation_id' => $this->correlationId,
        ]);

        $snapshot = $readinessService->generateSnapshot(
            $this->tenantId,
            $this->entityId,
            $this->periodId,
            $this->cycleId,
        );

        // Run AI anomaly analysis automatically after readiness refresh
        try {
            $aiResult = $aiAnalysisService->analyze($this->tenantId, $this->entityId, $this->periodId);
            Log::info('FinancialClose: AI analysis completed during readiness refresh', [
                'tenant_id' => $this->tenantId,
                'findings_created' => $aiResult['findings_created'],
                'accounts_analyzed' => $aiResult['accounts_analyzed'],
            ]);
        } catch (\Throwable $e) {
            Log::error('FinancialClose: AI analysis failed during readiness refresh', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            JobRunHistory::create([
                'tenant_id' => $this->tenantId,
                'job_type' => 'readiness_snapshot',
                'status' => 'completed',
                'started_at' => $startedAt,
                'completed_at' => now(),
                'duration_ms' => (int) ($startedAt->diffInMilliseconds(now())),
                'metadata' => [
                    'entity_id' => $this->entityId,
                    'period_id' => $this->periodId,
                    'cycle_id' => $this->cycleId,
                    'readiness_score' => $snapshot?->readiness_score,
                    'correlation_id' => $this->correlationId,
                ],
            ]);
        } catch (\Throwable $e) {
            JobRunHistory::create([
                'tenant_id' => $this->tenantId,
                'job_type' => 'readiness_snapshot',
                'status' => 'failed',
                'started_at' => $startedAt,
                'completed_at' => now(),
                'duration_ms' => (int) ($startedAt->diffInMilliseconds(now())),
                'error_message' => $e->getMessage(),
                'metadata' => [
                    'entity_id' => $this->entityId,
                    'period_id' => $this->periodId,
                    'cycle_id' => $this->cycleId,
                    'correlation_id' => $this->correlationId,
                ],
            ]);

            throw $e;
        }
    }
}
