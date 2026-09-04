<?php

namespace App\Accounting\Events;

use App\Models\AccountingOutboxEvent;
use App\Platform\EventBus\Events\DomainEvent;

/**
 * Bridge event for accounting outbox publication (issue #62).
 *
 * The accounting outbox (ADR-005 §62) was write-only: PostingEngine
 * inserted JournalPosted rows atomically with the ledger mutation, but
 * nothing ever read or published them. The consumer command
 * (accounting:publish-outbox) replays each unpublished row onto the
 * platform Domain Event Bus as this event, so existing
 * subscriptions/dead-letter/replay machinery receives it under the
 * canonical bus.
 */
class AccountingOutboxPublished extends DomainEvent
{
    protected string $eventName = 'accounting.outbox.published';

    protected string $version = '1.0.0';

    protected string $sourceModule = 'accounting';

    public function __construct(
        public readonly AccountingOutboxEvent $outboxEvent,
    ) {
        // domain_event_records.correlation_id is a uuid column and the
        // outbox correlation_id is nullable/arbitrary — normalize to a
        // valid uuid so a bridge insert can never poison the batch.
        $correlation = $outboxEvent->correlation_id;
        if (! is_string($correlation) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $correlation) !== 1) {
            $correlation = \Illuminate\Support\Str::uuid()->toString();
        }

        parent::__construct(
            tenantId: (string) $outboxEvent->tenant_id,
            userId: isset($outboxEvent->event_payload['posted_by'])
                ? (string) $outboxEvent->event_payload['posted_by']
                : null,
            correlationId: $correlation,
        );
    }

    public function getPayload(): array
    {
        return [
            'outbox_event_id' => $this->outboxEvent->id,
            'event_type' => $this->outboxEvent->event_type,
            'event_version' => $this->outboxEvent->event_version,
            'aggregate_type' => $this->outboxEvent->aggregate_type,
            'aggregate_id' => $this->outboxEvent->aggregate_id,
            'aggregate_version' => $this->outboxEvent->aggregate_version,
            'source_capability' => $this->outboxEvent->source_capability,
            'causation_id' => $this->outboxEvent->causation_id,
            'payload' => $this->outboxEvent->event_payload ?? [],
        ];
    }
}
