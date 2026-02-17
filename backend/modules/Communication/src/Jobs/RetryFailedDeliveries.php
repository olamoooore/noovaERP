<?php

namespace Modules\Communication\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Communication\Services\CommunicationEngine;

/**
 * RetryFailedDeliveries — Scheduled job that retries failed outbox items.
 *
 * Should be scheduled in Console/Kernel to run every 5 minutes:
 *   $schedule->job(new RetryFailedDeliveries())->everyFiveMinutes();
 */
class RetryFailedDeliveries implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct()
    {
        $this->queue = 'communication';
    }

    public function handle(CommunicationEngine $engine): void
    {
        $retried = $engine->retryFailed();

        if ($retried > 0) {
            \Illuminate\Support\Facades\Log::info("Communication: Retried {$retried} failed deliveries");
        }
    }
}
