<?php

namespace Modules\EventManagement\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\EventManagement\Services\DataHealthCheckService;
use Exception;

class DataHealthCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 3;
    public $backoff = [60, 120, 300]; // Exponential backoff

    /**
     * Execute the job.
     */
    public function handle(DataHealthCheckService $healthCheckService): void
    {
        try {
            Log::info('Starting scheduled data health checks');
            
            $results = $healthCheckService->runDataHealthChecks();
            
            // Count results by status
            $summary = [
                'total' => count($results),
                'passed' => 0,
                'warnings' => 0,
                'failed' => 0
            ];
            
            foreach ($results as $result) {
                switch ($result['status']) {
                    case 'passed':
                        $summary['passed']++;
                        break;
                    case 'warning':
                        $summary['warnings']++;
                        break;
                    case 'failed':
                        $summary['failed']++;
                        break;
                }
            }
            
            Log::info('Data health checks completed', $summary);
            
            // If there are critical failures, send immediate alerts
            if ($summary['failed'] > 0) {
                $this->sendCriticalAlerts($results);
            }
            
        } catch (Exception $e) {
            Log::error('Data health check job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }

    /**
     * Send critical alerts for failed health checks
     */
    private function sendCriticalAlerts(array $results): void
    {
        $criticalFailures = array_filter($results, function ($result) {
            return $result['status'] === 'failed' && $this->isCriticalCheck($result['check_name']);
        });
        
        foreach ($criticalFailures as $failure) {
            Log::critical('Critical data health check failure', [
                'check_name' => $failure['check_name'],
                'check_type' => $failure['check_type'],
                'expected' => $failure['expected_result'] ?? null,
                'actual' => $failure['actual_result'] ?? null,
                'error' => $failure['error'] ?? null
            ]);
            
            // TODO: Integrate with alerting system (Slack, PagerDuty, etc.)
            // This would send immediate notifications to operations team
        }
    }

    /**
     * Determine if a health check is critical
     */
    private function isCriticalCheck(string $checkName): bool
    {
        $criticalChecks = [
            'capacity_consistency',
            'pii_vault_integrity',
            'outbox_processing_health'
        ];
        
        return in_array($checkName, $criticalChecks);
    }

    /**
     * Handle job failure
     */
    public function failed(Exception $exception): void
    {
        Log::error('Data health check job failed permanently', [
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
        
        // TODO: Send alert about job failure to operations team
    }
}