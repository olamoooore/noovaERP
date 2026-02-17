<?php

namespace Modules\Communication\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Communication\Services\CommunicationEngine;

/**
 * ProcessOutboxBatch — Processes pending outbox items for a specific message.
 *
 * Dispatched automatically by CommunicationEngine after creating a message.
 * Handles the actual delivery through channel adapters.
 */
class ProcessOutboxBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    public function __construct(
        public int $messageId,
    ) {
        $this->queue = 'communication';
    }

    public function handle(CommunicationEngine $engine): void
    {
        $engine->processOutbox($this->messageId);
    }
}
