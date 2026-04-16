<?php

declare(strict_types=1);

namespace App\SuperAdmin\Jobs;

use App\SuperAdmin\Application\AdminAuditLogger;
use App\SuperAdmin\Application\TenantOperationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HealthCheckBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 300;

    public function __construct(
        public readonly array $tenantIds,
        public readonly string $batchId,
        public readonly int $initiatedByUserId,
    ) {}

    public function handle(TenantOperationService $operationService, AdminAuditLogger $auditLogger): void
    {
        $total        = count($this->tenantIds);
        $results      = [];
        $successCount = 0;
        $failureCount = 0;
        $startedAt    = now()->toIso8601String();

        $this->saveStatus([
            'status'        => 'running',
            'total'         => $total,
            'processed'     => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'results'       => [],
            'started_at'    => $startedAt,
            'completed_at'  => null,
        ]);

        foreach ($this->tenantIds as $tenantId) {
            try {
                $snapshot = $operationService->pingHealth((string) $tenantId);

                $results[] = [
                    'tenant_id'    => $tenantId,
                    'db_connected' => $snapshot->db_connected,
                    'storage_mb'   => $snapshot->storage_usage_mb,
                    'status'       => 'ok',
                ];

                $successCount++;
            } catch (\Throwable $e) {
                $errorMessage = $e->getMessage();

                $results[] = [
                    'tenant_id' => $tenantId,
                    'status'    => 'error',
                    'error'     => $errorMessage,
                ];

                $failureCount++;

                Log::warning('HealthCheckBatchJob: ping failed', [
                    'batch_id'  => $this->batchId,
                    'tenant_id' => $tenantId,
                    'error'     => $errorMessage,
                ]);
            }

            $processed = $successCount + $failureCount;

            $this->saveStatus([
                'status'        => 'running',
                'total'         => $total,
                'processed'     => $processed,
                'success_count' => $successCount,
                'failure_count' => $failureCount,
                'results'       => $results,
                'started_at'    => $startedAt,
                'completed_at'  => null,
            ]);
        }

        $completedAt = now()->toIso8601String();

        $this->saveStatus([
            'status'        => 'done',
            'total'         => $total,
            'processed'     => $total,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'results'       => $results,
            'started_at'    => $startedAt,
            'completed_at'  => $completedAt,
        ]);

        $auditLogger->log(
            action: 'operations.bulk_health_run',
            target: null,
            before: [],
            after: [
                'batch_id'      => $this->batchId,
                'total'         => $total,
                'success_count' => $successCount,
                'failure_count' => $failureCount,
            ],
            status: $failureCount === 0 ? 'success' : 'failure',
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('HealthCheckBatchJob failed', [
            'batch_id' => $this->batchId,
            'error'    => $exception->getMessage(),
        ]);

        $this->saveStatus([
            'status'       => 'failed',
            'error'        => $exception->getMessage(),
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    private function saveStatus(array $data): void
    {
        Cache::put("health_batch:{$this->batchId}", $data, now()->addHour());
    }
}
