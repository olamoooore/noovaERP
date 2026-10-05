<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Modules\Sales\Services\SalesOutboxPublisher;

/**
 * Issue #901 (PC7) — the second half of the Sales transactional-outbox
 * pattern: dispatch pending sales_event_outbox rows onto the platform Domain
 * Event Bus (which handles persistence, subscriptions, retry/dead-letter and
 * replay).
 *
 * Runs per tenant (the outbox and the bus live in the tenant schema). Rows are
 * marked published only after the bus accepted them, so a failure keeps the
 * row for the next run. Safe to run concurrently (FOR UPDATE SKIP LOCKED
 * inside the publisher) and idempotent with the post-commit immediate hook.
 */
class PublishSalesOutboxEvents extends Command
{
    protected $signature = 'sales:publish-outbox
                            {--batch-size=50 : Maximum number of outbox rows to attempt per tenant per run}
                            {--max-attempts=5 : Rows failing more than this many times are dead-lettered}';

    protected $description = 'Publish pending Sales domain events onto the Domain Event Bus';

    public function handle(SalesOutboxPublisher $publisher): int
    {
        $batchSize = max(1, (int) $this->option('batch-size'));
        $maxAttempts = max(1, (int) $this->option('max-attempts'));

        $currentTenantId = function_exists('tenant') && tenant()
            ? (string) (tenant('id') ?? '')
            : '';

        $totals = ['published' => 0, 'failed' => 0, 'dead_lettered' => 0];

        if ($currentTenantId !== '') {
            $totals = $this->accumulate($totals, $publisher->publishTenant($currentTenantId, $batchSize, $maxAttempts));
        } else {
            foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
                tenancy()->initialize($tenant);

                try {
                    $totals = $this->accumulate($totals, $publisher->publishTenant((string) $tenant->getTenantKey(), $batchSize, $maxAttempts));
                } finally {
                    tenancy()->end();
                }
            }
        }

        $this->info("Sales outbox publish: {$totals['published']} published, {$totals['failed']} failed, {$totals['dead_lettered']} dead-lettered.");

        return self::SUCCESS;
    }

    /**
     * @param  array{published: int, failed: int, dead_lettered: int}  $totals
     * @param  array{published: int, failed: int, dead_lettered: int}  $batch
     * @return array{published: int, failed: int, dead_lettered: int}
     */
    private function accumulate(array $totals, array $batch): array
    {
        return [
            'published' => $totals['published'] + $batch['published'],
            'failed' => $totals['failed'] + $batch['failed'],
            'dead_lettered' => $totals['dead_lettered'] + $batch['dead_lettered'],
        ];
    }
}
