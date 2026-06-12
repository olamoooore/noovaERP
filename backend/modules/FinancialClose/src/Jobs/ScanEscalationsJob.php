<?php

namespace Modules\FinancialClose\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Services\FinancialCloseEscalationService;
use Modules\FinancialClose\Models\JobRunHistory;

/**
 * Scheduled job — scans all open cycles for overdue tasks and creates escalations.
 * Safe to retry: duplicate-open-escalation guard is in EscalationService.
 */
class ScanEscalationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $cycleId,
        public readonly string $correlationId = '',
    ) {}

    public function handle(FinancialCloseEscalationService $escalationService): void
    {
        $startedAt = now();

        Log::info('FinancialClose: ScanEscalationsJob started', [
            'tenant_id'      => $this->tenantId,
            'cycle_id'       => $this->cycleId,
            'correlation_id' => $this->correlationId,
        ]);

        try {
            $count = $escalationService->processOverdueTasks($this->tenantId, $this->cycleId);

            JobRunHistory::create([
                'tenant_id'      => $this->tenantId,
                'job_type'       => 'escalation_scan',
                'status'         => 'completed',
                'started_at'     => $startedAt,
                'completed_at'   => now(),
                'duration_ms'    => (int) ($startedAt->diffInMilliseconds(now())),
                'metadata'       => [
                    'cycle_id'         => $this->cycleId,
                    'escalated_count'  => $count,
                    'correlation_id'   => $this->correlationId,
                ],
            ]);
        } catch (\Throwable $e) {
            JobRunHistory::create([
                'tenant_id'      => $this->tenantId,
                'job_type'       => 'escalation_scan',
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
