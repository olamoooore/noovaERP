<?php

namespace Modules\EventManagement\Jobs;

use Modules\EventManagement\Services\OutboxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * OutboxEventProcessor Job
 * 
 * Background job for processing outbox events with retry logic and monitoring.
 * Ensures exactly-once processing of side effects.
 */
class OutboxEventProcessor implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300; // 5 minutes

    /**
     * Batch size for processing events
     */
    private int $batchSize;

    /**
     * Create a new job instance.
     */
    public function __construct(int $batchSize = 50)
    {
        $this->batchSize = $batchSize;
        $this->onQueue('outbox-processing');
    }

    /**
     * Execute the job.
     */
    public function handle(OutboxService $outboxService): void
    {
        $startTime = microtime(true);
        
        Log::info('Starting outbox event processing', [
            'batch_size' => $this->batchSize,
            'job_id' => $this->job->getJobId()
        ]);

        try {
            // Process pending events
            $result = $outboxService->processPendingEvents($this->batchSize);
            
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::info('Outbox event processing completed', [
                'processed_count' => $result['processed_count'],
                'failed_count' => $result['failed_count'],
                'processing_time_ms' => $processingTime,
                'job_id' => $this->job->getJobId()
            ]);

            // If there are more events to process, dispatch another job
            if ($result['processed_count'] > 0 && $result['processed_count'] >= $this->batchSize) {
                Log::info('Dispatching next outbox processing job', [
                    'reason' => 'batch_size_reached'
                ]);
                
                self::dispatch($this->batchSize)->delay(now()->addSeconds(5));
            }

            // Log failed events for monitoring
            if ($result['failed_count'] > 0) {
                Log::warning('Some outbox events failed processing', [
                    'failed_count' => $result['failed_count'],
                    'failed_events' => $result['failed_events']
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Outbox event processing job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'job_id' => $this->job->getJobId()
            ]);

            // Re-throw to trigger job retry mechanism
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Outbox event processing job failed permanently', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'attempts' => $this->attempts(),
            'job_id' => $this->job?->getJobId()
        ]);

        // Could send alert to monitoring system here
        // AlertService::sendAlert('outbox_processing_failed', [
        //     'error' => $exception->getMessage(),
        //     'attempts' => $this->attempts()
        // ]);
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        // Exponential backoff: 30s, 60s, 120s
        return [30, 60, 120];
    }

    /**
     * Determine if the job should be retried based on the exception.
     */
    public function retryUntil(): \DateTime
    {
        // Retry for up to 10 minutes
        return now()->addMinutes(10);
    }
}