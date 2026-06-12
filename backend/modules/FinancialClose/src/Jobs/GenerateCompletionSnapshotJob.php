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
        tenancy()->initialize($this->tenantId);

        $startedAt = now();

        Log::info('FinancialClose: GenerateCompletionSnapshotJob started', [
            'tenant_id'      => $this->tenantId,
            'cycle_id'       => $this->cycleId,
            'correlation_id' => $this->correlationId,
        ]);

        try {
            $snapshot = $completionService->generateSnapshot($this->tenantId, $this->cycleId);

            JobRunHistory::create([
                'tenant_id'      => $this->tenantId,
                'job_type'       => 'completion_snapshot',
                'status'         => 'completed',
                'started_at'     => $startedAt,
                'completed_at'   => now(),
                'duration_ms'    => (int) ($startedAt->diffInMilliseconds(now())),
                'metadata'       => [
                    'cycle_id'             => $this->cycleId,
                    'completion_percentage' => $snapshot?->completion_percentage,
                    'correlation_id'       => $this->correlationId,
                ],
            ]);
        } catch (\Throwable $e) {
            JobRunHistory::create([
                'tenant_id'      => $this->tenantId,
                'job_type'       => 'completion_snapshot',
                'status'         => 'failed',
                'started_at'     => $startedAt,
                'completed_at'   => now(),
                'duration_ms'    => (int) ($startedAt->diffInMilliseconds(now())),
                'error_message'  => $e->getMessage(),
                'metadata'       => [
                    'cycle_id'       => $this->cycleId,
                    'correlation_id' => $this->correlationId,
                ],
            ]);

            throw $e;
        }
    }
}
