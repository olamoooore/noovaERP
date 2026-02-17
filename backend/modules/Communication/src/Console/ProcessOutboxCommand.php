<?php

namespace Modules\Communication\Console;

use Illuminate\Console\Command;
use Modules\Communication\Services\CommunicationEngine;

/**
 * Artisan command: php artisan communication:process-outbox
 *
 * Manually processes pending outbox items. Useful for debugging
 * or environments without a queue worker.
 */
class ProcessOutboxCommand extends Command
{
    protected $signature = 'communication:process-outbox
                            {--message= : Process specific message ID}
                            {--retry-failed : Retry failed deliveries}
                            {--limit=100 : Max items to process}';

    protected $description = 'Process pending communication outbox items';

    public function handle(CommunicationEngine $engine): int
    {
        if ($this->option('retry-failed')) {
            $count = $engine->retryFailed();
            $this->info("Retried {$count} failed deliveries.");
            return self::SUCCESS;
        }

        $messageId = $this->option('message');

        if ($messageId) {
            $engine->processOutbox((int) $messageId);
            $this->info("Processed outbox for message #{$messageId}.");
        } else {
            // Process all pending
            $pending = \Modules\Communication\Models\CommunicationOutbox::readyToSend()
                ->limit((int) $this->option('limit'))
                ->pluck('message_id')
                ->unique();

            foreach ($pending as $msgId) {
                $engine->processOutbox($msgId);
            }

            $this->info("Processed outbox for {$pending->count()} messages.");
        }

        return self::SUCCESS;
    }
}
