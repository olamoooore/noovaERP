<?php

namespace Modules\EventManagement\Console\Commands;

use Modules\EventManagement\Jobs\OutboxEventProcessor;
use Modules\EventManagement\Services\OutboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ProcessOutboxEventsCommand
 * 
 * Console command to process outbox events. Can be run manually or scheduled.
 */
class ProcessOutboxEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'event-management:process-outbox 
                           {--batch-size=50 : Number of events to process in each batch}
                           {--sync : Process synchronously instead of dispatching job}
                           {--stats : Show processing statistics}';

    /**
     * The console command description.
     */
    protected $description = 'Process pending outbox events for exactly-once side effects';

    /**
     * Execute the console command.
     */
    public function handle(OutboxService $outboxService): int
    {
        $batchSize = (int) $this->option('batch-size');
        $sync = $this->option('sync');
        $showStats = $this->option('stats');

        if ($showStats) {
            $this->showStatistics($outboxService);
            return self::SUCCESS;
        }

        $this->info("Processing outbox events (batch size: {$batchSize})");

        if ($sync) {
            return $this->processSynchronously($outboxService, $batchSize);
        } else {
            return $this->processAsynchronously($batchSize);
        }
    }

    /**
     * Process events synchronously
     */
    private function processSynchronously(OutboxService $outboxService, int $batchSize): int
    {
        try {
            $startTime = microtime(true);
            
            $result = $outboxService->processPendingEvents($batchSize);
            
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->info("Processing completed:");
            $this->line("  - Processed: {$result['processed_count']} events");
            $this->line("  - Failed: {$result['failed_count']} events");
            $this->line("  - Processing time: {$processingTime}ms");

            if ($result['failed_count'] > 0) {
                $this->warn("Failed events:");
                foreach ($result['failed_events'] as $failedEvent) {
                    $this->line("  - Event ID {$failedEvent['event_id']}: {$failedEvent['error']}");
                }
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Processing failed: " . $e->getMessage());
            Log::error('Outbox processing command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return self::FAILURE;
        }
    }

    /**
     * Process events asynchronously by dispatching job
     */
    private function processAsynchronously(int $batchSize): int
    {
        try {
            OutboxEventProcessor::dispatch($batchSize);
            
            $this->info("Outbox processing job dispatched (batch size: {$batchSize})");
            $this->line("Check logs for processing results.");
            
            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Failed to dispatch processing job: " . $e->getMessage());
            
            return self::FAILURE;
        }
    }

    /**
     * Show processing statistics
     */
    private function showStatistics(OutboxService $outboxService): void
    {
        $stats = $outboxService->getProcessingStatistics();
        
        $this->info("Outbox Processing Statistics");
        $this->line("==========================");
        
        $this->line("Event Status:");
        $this->line("  - Total: {$stats['total_events']}");
        $this->line("  - Pending: {$stats['pending_events']}");
        $this->line("  - Processing: {$stats['processing_events']}");
        $this->line("  - Completed: {$stats['completed_events']}");
        $this->line("  - Failed: {$stats['failed_events']}");
        
        if (!empty($stats['events_by_type'])) {
            $this->line("");
            $this->line("Events by Type:");
            foreach ($stats['events_by_type'] as $type => $count) {
                $this->line("  - {$type}: {$count}");
            }
        }
        
        if (!empty($stats['consumer_statistics'])) {
            $this->line("");
            $this->line("Consumer Statistics:");
            foreach ($stats['consumer_statistics'] as $consumer => $statuses) {
                $this->line("  {$consumer}:");
                foreach ($statuses as $status => $count) {
                    $this->line("    - {$status}: {$count}");
                }
            }
        }
    }
}