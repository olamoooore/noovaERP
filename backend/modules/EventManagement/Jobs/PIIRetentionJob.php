<?php

namespace Modules\EventManagement\Jobs;

use Modules\EventManagement\Services\PIIVaultService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * PIIRetentionJob - Task 4.3
 * 
 * Scheduled job for PII retention policy enforcement with automated anonymization
 * for expired data and GDPR compliance workflows.
 * 
 * Requirements: 20.2, 20.5
 */
class PIIRetentionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $batchSize;
    protected bool $dryRun;
    protected array $retentionPolicies;

    /**
     * Create a new job instance.
     *
     * @param int $batchSize Number of records to process per batch
     * @param bool $dryRun Whether to perform a dry run (no actual changes)
     * @param array $retentionPolicies Specific policies to process (empty for all)
     */
    public function __construct(
        int $batchSize = 100,
        bool $dryRun = false,
        array $retentionPolicies = []
    ) {
        $this->batchSize = $batchSize;
        $this->dryRun = $dryRun;
        $this->retentionPolicies = $retentionPolicies;
        
        // Set queue and connection
        $this->onQueue('pii-retention');
        $this->onConnection('redis');
    }

    /**
     * Execute the job.
     *
     * @param PIIVaultService $piiVaultService
     * @return void
     * 
     * Requirements: 20.2, 20.5
     */
    public function handle(PIIVaultService $piiVaultService): void
    {
        $startTime = microtime(true);
        $processedCount = 0;
        $anonymizedCount = 0;
        $errorCount = 0;
        $skippedCount = 0;
        
        Log::info('Starting PII retention job', [
            'batch_size' => $this->batchSize,
            'dry_run' => $this->dryRun,
            'retention_policies' => $this->retentionPolicies,
        ]);

        try {
            // Step 1: Process expired PII records in batches
            do {
                $expiredRecords = $this->getExpiredPIIRecords();
                
                if (empty($expiredRecords)) {
                    break;
                }
                
                foreach ($expiredRecords as $record) {
                    try {
                        $processedCount++;
                        
                        // Check if record should be processed based on policy filter
                        if (!empty($this->retentionPolicies) && 
                            !in_array($record->retention_policy, $this->retentionPolicies)) {
                            $skippedCount++;
                            continue;
                        }
                        
                        // Get retention policy details
                        $policyDetails = $this->getRetentionPolicyDetails($record->retention_policy);
                        
                        if (!$policyDetails) {
                            Log::warning('Unknown retention policy', [
                                'vault_id' => $record->id,
                                'retention_policy' => $record->retention_policy,
                            ]);
                            $errorCount++;
                            continue;
                        }
                        
                        // Perform anonymization if not dry run
                        if (!$this->dryRun) {
                            $result = $piiVaultService->anonymizePII(
                                $record->id,
                                $policyDetails['anonymization_method'],
                                "Retention policy: {$record->retention_policy}"
                            );
                            
                            if ($result['success']) {
                                $anonymizedCount++;
                                
                                Log::debug('PII record anonymized', [
                                    'vault_id' => $record->id,
                                    'entity_type' => $record->entity_type,
                                    'entity_id' => $record->entity_id,
                                    'field_name' => $record->field_name,
                                    'retention_policy' => $record->retention_policy,
                                    'method' => $policyDetails['anonymization_method'],
                                ]);
                            } else {
                                $errorCount++;
                                Log::error('Failed to anonymize PII record', [
                                    'vault_id' => $record->id,
                                    'error' => $result['error'] ?? 'Unknown error',
                                ]);
                            }
                        } else {
                            // Dry run - just log what would be done
                            Log::info('DRY RUN: Would anonymize PII record', [
                                'vault_id' => $record->id,
                                'entity_type' => $record->entity_type,
                                'entity_id' => $record->entity_id,
                                'field_name' => $record->field_name,
                                'retention_policy' => $record->retention_policy,
                                'method' => $policyDetails['anonymization_method'],
                                'expired_days' => Carbon::now()->diffInDays(Carbon::parse($record->expires_at)),
                            ]);
                            $anonymizedCount++;
                        }
                        
                    } catch (\Exception $e) {
                        $errorCount++;
                        Log::error('Error processing PII record for retention', [
                            'vault_id' => $record->id ?? 'unknown',
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }
                
                // Brief pause between batches to reduce system load
                if (count($expiredRecords) === $this->batchSize) {
                    usleep(100000); // 100ms pause
                }
                
            } while (count($expiredRecords) === $this->batchSize);

            // Step 2: Process expired consent records
            $expiredConsentCount = $this->processExpiredConsent();

            // Step 3: Clean up old export requests
            $cleanedExportRequests = $this->cleanupExpiredExportRequests();

            // Step 4: Generate retention summary
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $summary = [
                'processed_records' => $processedCount,
                'anonymized_records' => $anonymizedCount,
                'skipped_records' => $skippedCount,
                'error_count' => $errorCount,
                'expired_consent_processed' => $expiredConsentCount,
                'cleaned_export_requests' => $cleanedExportRequests,
                'execution_time_ms' => $executionTime,
                'dry_run' => $this->dryRun,
                'timestamp' => Carbon::now()->toISOString(),
            ];

            Log::info('PII retention job completed', $summary);

            // Step 5: Check for critical issues and alert if necessary
            $this->checkForCriticalIssues($summary);

        } catch (\Exception $e) {
            Log::error('PII retention job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'processed_count' => $processedCount,
                'error_count' => $errorCount,
            ]);
            
            // Re-throw to mark job as failed
            throw $e;
        }
    }

    /**
     * Get expired PII records that need anonymization.
     * 
     * @return array List of expired PII records
     */
    private function getExpiredPIIRecords(): array
    {
        return DB::table('pii_vault')
            ->whereNull('anonymized_at')
            ->where('expires_at', '<', Carbon::now())
            ->limit($this->batchSize)
            ->get()
            ->toArray();
    }

    /**
     * Get retention policy details.
     * 
     * @param string $policyName Policy name
     * @return array|null Policy details or null if not found
     */
    private function getRetentionPolicyDetails(string $policyName): ?array
    {
        $policy = DB::table('pii_retention_policies')
            ->where('policy_name', $policyName)
            ->where('is_active', true)
            ->first();
        
        if (!$policy) {
            // Return default policy for unknown policies
            return [
                'anonymization_method' => 'hash',
                'retention_days' => 2555, // 7 years default
                'legal_basis' => 'Data retention policy',
            ];
        }
        
        return [
            'anonymization_method' => $policy->anonymization_method,
            'retention_days' => $policy->retention_days,
            'legal_basis' => $policy->legal_basis,
            'compliance_framework' => $policy->compliance_framework,
        ];
    }

    /**
     * Process expired consent records.
     * 
     * @return int Number of expired consent records processed
     */
    private function processExpiredConsent(): int
    {
        if ($this->dryRun) {
            // Count what would be processed
            return DB::table('pii_consent_tracking')
                ->whereNull('consent_withdrawn_at')
                ->where('consent_expires_at', '<', Carbon::now())
                ->count();
        }
        
        // Mark expired consent as withdrawn
        $expiredCount = DB::table('pii_consent_tracking')
            ->whereNull('consent_withdrawn_at')
            ->where('consent_expires_at', '<', Carbon::now())
            ->update([
                'consent_withdrawn_at' => Carbon::now(),
                'withdrawal_reason' => 'Consent expired automatically',
            ]);
        
        if ($expiredCount > 0) {
            Log::info('Processed expired consent records', [
                'expired_count' => $expiredCount,
            ]);
        }
        
        return $expiredCount;
    }

    /**
     * Clean up expired export requests and their files.
     * 
     * @return int Number of export requests cleaned up
     */
    private function cleanupExpiredExportRequests(): int
    {
        // Get expired export requests with files
        $expiredRequests = DB::table('pii_export_requests')
            ->where('status', 'completed')
            ->where('file_expires_at', '<', Carbon::now())
            ->whereNotNull('export_file_path')
            ->get();
        
        $cleanedCount = 0;
        
        foreach ($expiredRequests as $request) {
            try {
                if (!$this->dryRun) {
                    // Delete the export file if it exists
                    if (file_exists($request->export_file_path)) {
                        unlink($request->export_file_path);
                    }
                    
                    // Update the request record
                    DB::table('pii_export_requests')
                        ->where('id', $request->id)
                        ->update([
                            'export_file_path' => null,
                            'file_expires_at' => null,
                        ]);
                }
                
                $cleanedCount++;
                
                Log::debug('Cleaned up expired export request', [
                    'request_id' => $request->id,
                    'request_reference' => $request->request_reference,
                    'file_path' => $request->export_file_path,
                    'dry_run' => $this->dryRun,
                ]);
                
            } catch (\Exception $e) {
                Log::error('Failed to clean up export request', [
                    'request_id' => $request->id,
                    'file_path' => $request->export_file_path,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        return $cleanedCount;
    }

    /**
     * Check for critical issues and send alerts if necessary.
     * 
     * @param array $summary Job execution summary
     */
    private function checkForCriticalIssues(array $summary): void
    {
        $criticalIssues = [];
        
        // Check for high error rate
        if ($summary['processed_records'] > 0) {
            $errorRate = ($summary['error_count'] / $summary['processed_records']) * 100;
            
            if ($errorRate > 10) { // More than 10% error rate
                $criticalIssues[] = [
                    'type' => 'high_error_rate',
                    'message' => "High error rate during PII retention: {$errorRate}%",
                    'data' => [
                        'error_count' => $summary['error_count'],
                        'processed_count' => $summary['processed_records'],
                        'error_rate' => $errorRate,
                    ],
                ];
            }
        }
        
        // Check for long execution time
        if ($summary['execution_time_ms'] > 60000) { // More than 60 seconds
            $criticalIssues[] = [
                'type' => 'long_execution_time',
                'message' => "PII retention job took {$summary['execution_time_ms']}ms to complete",
                'data' => [
                    'execution_time_ms' => $summary['execution_time_ms'],
                    'processed_count' => $summary['processed_records'],
                ],
            ];
        }
        
        // Check for large number of records to process (potential backlog)
        if ($summary['processed_records'] >= $this->batchSize * 10) {
            $criticalIssues[] = [
                'type' => 'large_backlog',
                'message' => "Large PII retention backlog detected: {$summary['processed_records']} records",
                'data' => [
                    'processed_count' => $summary['processed_records'],
                    'batch_size' => $this->batchSize,
                ],
            ];
        }
        
        // Send alerts for critical issues
        if (!empty($criticalIssues)) {
            $this->sendCriticalIssueAlerts($criticalIssues, $summary);
        }
    }

    /**
     * Send alerts for critical issues.
     * 
     * @param array $criticalIssues List of critical issues
     * @param array $summary Job summary
     */
    private function sendCriticalIssueAlerts(array $criticalIssues, array $summary): void
    {
        foreach ($criticalIssues as $issue) {
            Log::critical('PII retention critical issue', [
                'issue_type' => $issue['type'],
                'message' => $issue['message'],
                'data' => $issue['data'],
                'job_summary' => $summary,
                'timestamp' => Carbon::now()->toISOString(),
            ]);
            
            // Here you would integrate with your alerting system
            // Examples: Slack notifications, email alerts, PagerDuty, etc.
            $this->sendAlert($issue, $summary);
        }
    }

    /**
     * Send alert to monitoring/alerting system.
     * 
     * @param array $issue Critical issue details
     * @param array $summary Job summary
     */
    private function sendAlert(array $issue, array $summary): void
    {
        // This would integrate with your specific alerting system
        $alertData = [
            'alert_type' => 'pii_retention_critical',
            'severity' => 'critical',
            'service' => 'erp_event_management',
            'component' => 'pii_retention_job',
            'issue' => $issue,
            'summary' => $summary,
            'timestamp' => Carbon::now()->toISOString(),
        ];
        
        Log::info('Critical alert would be sent', $alertData);
        
        // Integration points for alerting systems:
        // - Slack webhook notification
        // - Email alert to operations team
        // - PagerDuty incident creation
        // - Metrics push to monitoring system
    }

    /**
     * Handle job failure.
     * 
     * @param \Throwable $exception
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('PII retention job failed permanently', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'job_data' => [
                'batch_size' => $this->batchSize,
                'dry_run' => $this->dryRun,
                'retention_policies' => $this->retentionPolicies,
            ],
            'timestamp' => Carbon::now()->toISOString(),
        ]);
        
        // Send failure alert
        $this->sendAlert([
            'type' => 'job_failure',
            'message' => 'PII retention job failed permanently',
            'data' => [
                'error' => $exception->getMessage(),
                'job_attempts' => $this->attempts(),
            ],
        ], [
            'status' => 'failed',
            'timestamp' => Carbon::now()->toISOString(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array
     */
    public function tags(): array
    {
        return [
            'pii-retention',
            'privacy',
            'gdpr',
            'batch-size:' . $this->batchSize,
        ];
    }
}