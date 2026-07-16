<?php

namespace Modules\EventManagement\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\EventManagement\Services\CapacityReservationService;

/**
 * CapacityReservationCleanupJob - Task 3.3
 *
 * Scheduled job for expired reservation cleanup with monitoring and alerting.
 * Implements capacity reconciliation and audit reporting.
 *
 * Requirements: 19.4, 19.5
 */
class CapacityReservationCleanupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $batchSize;

    protected bool $performReconciliation;

    protected array $eventIds;

    /**
     * Create a new job instance.
     *
     * @param  int  $batchSize  Number of reservations to process per batch
     * @param  bool  $performReconciliation  Whether to perform capacity reconciliation
     * @param  array  $eventIds  Specific event IDs to process (empty for all events)
     */
    public function __construct(
        int $batchSize = 100,
        bool $performReconciliation = true,
        array $eventIds = []
    ) {
        $this->batchSize = $batchSize;
        $this->performReconciliation = $performReconciliation;
        $this->eventIds = $eventIds;

        // Set queue and connection
        $this->onQueue('capacity-management');
        $this->onConnection('redis');
    }

    /**
     * Execute the job.
     *
     * @return void
     *
     * Requirements: 19.4, 19.5
     */
    public function handle(CapacityReservationService $capacityService): void
    {
        $startTime = microtime(true);
        $processedCount = 0;
        $errorCount = 0;
        $reconciledEvents = [];

        Log::info('Starting capacity reservation cleanup job', [
            'batch_size' => $this->batchSize,
            'perform_reconciliation' => $this->performReconciliation,
            'specific_events' => ! empty($this->eventIds) ? $this->eventIds : 'all',
        ]);

        try {
            // Step 1: Process expired reservations in batches
            do {
                $expiredReservations = $capacityService->getExpiredReservations($this->batchSize);

                if (empty($expiredReservations)) {
                    break;
                }

                foreach ($expiredReservations as $reservationId) {
                    try {
                        $success = $capacityService->expireReservation($reservationId);

                        if ($success) {
                            $processedCount++;
                        } else {
                            $errorCount++;
                            Log::warning('Failed to expire reservation', [
                                'reservation_id' => $reservationId,
                                'reason' => 'Reservation not found or already processed',
                            ]);
                        }

                    } catch (\Exception $e) {
                        $errorCount++;
                        Log::error('Error expiring reservation', [
                            'reservation_id' => $reservationId,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }

                // Brief pause between batches to reduce system load
                if (count($expiredReservations) === $this->batchSize) {
                    usleep(100000); // 100ms pause
                }

            } while (count($expiredReservations) === $this->batchSize);

            // Step 2: Perform capacity reconciliation if requested
            if ($this->performReconciliation) {
                $reconciledEvents = $this->performCapacityReconciliation($capacityService);
            }

            // Step 3: Generate cleanup summary
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $summary = [
                'processed_reservations' => $processedCount,
                'error_count' => $errorCount,
                'reconciled_events' => count($reconciledEvents),
                'execution_time_ms' => $executionTime,
                'timestamp' => Carbon::now()->toISOString(),
            ];

            Log::info('Capacity reservation cleanup completed', $summary);

            // Step 4: Check for critical issues and alert if necessary
            $this->checkForCriticalIssues($summary, $reconciledEvents);

        } catch (\Exception $e) {
            Log::error('Capacity reservation cleanup job failed', [
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
     * Perform capacity reconciliation for events.
     *
     * @return array Reconciliation results
     *
     * Requirements: 19.5
     */
    private function performCapacityReconciliation(CapacityReservationService $capacityService): array
    {
        $reconciledEvents = [];
        $criticalIssues = [];

        try {
            // Get events to reconcile
            $eventsToReconcile = $this->getEventsForReconciliation();

            Log::info('Starting capacity reconciliation', [
                'event_count' => count($eventsToReconcile),
                'event_ids' => $eventsToReconcile,
            ]);

            foreach ($eventsToReconcile as $eventId) {
                try {
                    $reconciliationResult = $capacityService->reconcileEventCapacity($eventId);
                    $reconciledEvents[] = $reconciliationResult;

                    // Track critical issues
                    if ($reconciliationResult['status'] === 'critical') {
                        $criticalIssues[] = $reconciliationResult;
                    }

                    Log::debug('Event capacity reconciled', [
                        'event_id' => $eventId,
                        'status' => $reconciliationResult['status'],
                        'discrepancy' => $reconciliationResult['available_discrepancy'],
                    ]);

                } catch (\Exception $e) {
                    Log::error('Failed to reconcile event capacity', [
                        'event_id' => $eventId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Log critical issues summary
            if (! empty($criticalIssues)) {
                Log::warning('Critical capacity discrepancies detected', [
                    'critical_event_count' => count($criticalIssues),
                    'critical_events' => array_column($criticalIssues, 'event_id'),
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Capacity reconciliation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $reconciledEvents;
    }

    /**
     * Get list of events that need capacity reconciliation.
     *
     * @return array Event IDs to reconcile
     */
    private function getEventsForReconciliation(): array
    {
        // If specific event IDs provided, use those
        if (! empty($this->eventIds)) {
            return $this->eventIds;
        }

        // Otherwise, get events with recent capacity activity
        return \DB::table('events')
            ->where('status', 'published')
            ->where('event_date', '>', Carbon::now())
            ->where(function ($query) {
                $query->where('reserved_capacity', '>', 0)
                    ->orWhere('capacity_last_updated', '>', Carbon::now()->subHours(24));
            })
            ->pluck('id')
            ->toArray();
    }

    /**
     * Check for critical issues and send alerts if necessary.
     *
     * @param  array  $summary  Cleanup summary
     * @param  array  $reconciledEvents  Reconciliation results
     */
    private function checkForCriticalIssues(array $summary, array $reconciledEvents): void
    {
        $criticalIssues = [];

        // Check for high error rate
        if ($summary['processed_reservations'] > 0) {
            $errorRate = ($summary['error_count'] / ($summary['processed_reservations'] + $summary['error_count'])) * 100;

            if ($errorRate > 10) { // More than 10% error rate
                $criticalIssues[] = [
                    'type' => 'high_error_rate',
                    'message' => "High error rate during cleanup: {$errorRate}%",
                    'data' => [
                        'error_count' => $summary['error_count'],
                        'processed_count' => $summary['processed_reservations'],
                        'error_rate' => $errorRate,
                    ],
                ];
            }
        }

        // Check for critical capacity discrepancies
        $criticalReconciliations = array_filter($reconciledEvents, function ($result) {
            return $result['status'] === 'critical';
        });

        if (! empty($criticalReconciliations)) {
            $criticalIssues[] = [
                'type' => 'critical_capacity_discrepancy',
                'message' => 'Critical capacity discrepancies detected in '.count($criticalReconciliations).' events',
                'data' => [
                    'affected_events' => array_column($criticalReconciliations, 'event_id'),
                    'discrepancies' => $criticalReconciliations,
                ],
            ];
        }

        // Check for long execution time
        if ($summary['execution_time_ms'] > 30000) { // More than 30 seconds
            $criticalIssues[] = [
                'type' => 'long_execution_time',
                'message' => "Cleanup job took {$summary['execution_time_ms']}ms to complete",
                'data' => [
                    'execution_time_ms' => $summary['execution_time_ms'],
                    'processed_count' => $summary['processed_reservations'],
                ],
            ];
        }

        // Send alerts for critical issues
        if (! empty($criticalIssues)) {
            $this->sendCriticalIssueAlerts($criticalIssues, $summary);
        }
    }

    /**
     * Send alerts for critical issues.
     *
     * @param  array  $criticalIssues  List of critical issues
     * @param  array  $summary  Job summary
     */
    private function sendCriticalIssueAlerts(array $criticalIssues, array $summary): void
    {
        foreach ($criticalIssues as $issue) {
            Log::critical('Capacity management critical issue', [
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
     * @param  array  $issue  Critical issue details
     * @param  array  $summary  Job summary
     */
    private function sendAlert(array $issue, array $summary): void
    {
        // This would integrate with your specific alerting system
        // For now, we'll just log it as a critical alert

        $alertData = [
            'alert_type' => 'capacity_management_critical',
            'severity' => 'critical',
            'service' => 'erp_event_management',
            'component' => 'capacity_reservation_cleanup',
            'issue' => $issue,
            'summary' => $summary,
            'timestamp' => Carbon::now()->toISOString(),
        ];

        // Example integrations (uncomment and configure as needed):

        // Slack notification
        // \Notification::route('slack', config('alerts.slack.webhook'))
        //     ->notify(new \App\Notifications\CriticalCapacityAlert($alertData));

        // Email notification
        // \Mail::to(config('alerts.email.recipients'))
        //     ->send(new \App\Mail\CriticalCapacityAlert($alertData));

        // PagerDuty integration
        // app('pagerduty')->trigger([
        //     'routing_key' => config('alerts.pagerduty.routing_key'),
        //     'event_action' => 'trigger',
        //     'payload' => [
        //         'summary' => $issue['message'],
        //         'severity' => 'critical',
        //         'source' => 'capacity_reservation_cleanup',
        //         'custom_details' => $alertData,
        //     ],
        // ]);

        Log::info('Critical alert would be sent', $alertData);
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Capacity reservation cleanup job failed permanently', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'job_data' => [
                'batch_size' => $this->batchSize,
                'perform_reconciliation' => $this->performReconciliation,
                'event_ids' => $this->eventIds,
            ],
            'timestamp' => Carbon::now()->toISOString(),
        ]);

        // Send failure alert
        $this->sendAlert([
            'type' => 'job_failure',
            'message' => 'Capacity reservation cleanup job failed permanently',
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
     */
    public function tags(): array
    {
        return [
            'capacity-management',
            'cleanup',
            'reservations',
            'batch-size:'.$this->batchSize,
        ];
    }
}
