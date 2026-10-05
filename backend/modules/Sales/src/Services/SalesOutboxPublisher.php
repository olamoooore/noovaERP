<?php

namespace Modules\Sales\Services;

use App\Platform\EventBus\DomainEventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Events\SalesDomainEvent;
use Modules\Sales\Models\SalesEventOutboxEvent;

/**
 * SalesOutboxPublisher — dispatches durable Sales outbox rows onto the
 * platform Domain Event Bus (issue #901, PC7).
 *
 * Reuses the platform DomainEventDispatcher (no second event system): the bus
 * persists the event in domain_event_records, routes it to subscriptions and
 * dead-letters failing consumers in domain_event_dead_letters. A row is marked
 * published only after the bus accepted it, so publication is at-least-once;
 * rows whose whole publish fails accumulate attempts and are explicitly
 * dead-lettered once the budget is spent. Consumer-side idempotency (event id)
 * makes the at-least-once delivery safe.
 */
class SalesOutboxPublisher
{
    public function __construct(private DomainEventDispatcher $dispatcher) {}

    /**
     * Publish one outbox row (used by the post-commit hook and tests).
     * Returns true only when the row was newly published.
     */
    public function publishRow(string $id, int $maxAttempts = 5): bool
    {
        $row = SalesEventOutboxEvent::query()->find($id);

        if (! $row) {
            return false;
        }

        return $this->publishRowInstance($row, $maxAttempts);
    }

    /**
     * Publish the pending rows of one tenant under a transaction (the command
     * already initialized the tenant schema; callers inside tests run in the
     * bound tenant context).
     *
     * @return array{published: int, failed: int, dead_lettered: int}
     */
    public function publishTenant(string $tenantId, int $batchSize = 50, int $maxAttempts = 5): array
    {
        if (! DB::getSchemaBuilder()->hasTable('sales_event_outbox')) {
            return ['published' => 0, 'failed' => 0, 'dead_lettered' => 0];
        }

        return DB::transaction(function () use ($tenantId, $batchSize, $maxAttempts) {
            // FOR UPDATE SKIP LOCKED: overlapping publisher runs never publish
            // the same row twice.
            $rows = SalesEventOutboxEvent::query()
                ->where('tenant_id', $tenantId)
                ->pending()
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->limit($batchSize)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            $published = 0;
            $failed = 0;

            foreach ($rows as $row) {
                if ($this->publishRowInstance($row, $maxAttempts)) {
                    $published++;
                } else {
                    $failed++;
                }
            }

            // Sweep rows already past the attempt budget (legacy backlog) into
            // the explicit terminal disposition rather than leaving them
            // silently stranded.
            $stranded = SalesEventOutboxEvent::query()
                ->where('tenant_id', $tenantId)
                ->pending()
                ->where('publish_attempts', '>=', $maxAttempts)
                ->get();

            foreach ($stranded as $row) {
                $row->markDeadLettered();
            }

            return [
                'published' => $published,
                'failed' => $failed,
                'dead_lettered' => $stranded->count(),
            ];
        });
    }

    private function publishRowInstance(SalesEventOutboxEvent $row, int $maxAttempts): bool
    {
        if ($row->isPublished() || $row->isDeadLettered()) {
            return false;
        }

        if ($row->publish_attempts >= $maxAttempts) {
            $row->markDeadLettered();

            return false;
        }

        try {
            $this->dispatcher->dispatch(SalesDomainEvent::fromOutbox($row));

            $row->markPublished();

            return true;
        } catch (\Throwable $e) {
            // The row stays durable for the scheduled retry; the error is
            // recorded on the row and in the log, never swallowed.
            $row->markFailed($e->getMessage());

            if ($row->publish_attempts >= $maxAttempts) {
                $row->markDeadLettered();
            }

            Log::warning('Sales outbox publish failed; row retained for retry', [
                'outbox_event_id' => $row->id,
                'event_name' => $row->event_name,
                'attempt' => $row->publish_attempts,
                'dead_lettered' => $row->isDeadLettered(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
