<?php

namespace Modules\Sales\Listeners;

use App\Platform\EventBus\Contracts\DomainEventContract;
use App\Platform\EventBus\Contracts\EventHandlerContract;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesEventAuditLog;

/**
 * SalesEventAuditConsumer — proof consumer for the #901 sales event catalogue.
 *
 * Subscribed to every SalesEventCatalogue event, it records each event in the
 * tenant-scoped sales_event_audit_log. Idempotent: the unique event_id is the
 * processed marker, so a redelivered event (same id) is not processed twice.
 * A failure propagates so the DomainEventDispatcher dead-letters the event
 * (domain_event_dead_letters) instead of silently dropping it.
 *
 * This is deliberately the only sales consumer wired here; downstream business
 * consumers are tracked separately (#903).
 */
class SalesEventAuditConsumer implements EventHandlerContract
{
    public function handle(DomainEventContract $event): void
    {
        if (! Schema::hasTable('sales_event_audit_log')) {
            return;
        }

        $payload = $event->getPayload();
        $eventId = (string) ($payload['event_id'] ?? $event->getCorrelationId());

        // createOrFirst-style semantics: unique(event_id) makes a concurrent
        // or replayed delivery lose the race without a duplicate row.
        SalesEventAuditLog::firstOrCreate(
            ['event_id' => $eventId],
            [
                'id' => Str::uuid(),
                'tenant_id' => $event->getTenantId(),
                'event_name' => $event->getEventName(),
                'version' => $event->getVersion(),
                'document_type' => $payload['document_type'] ?? null,
                'document_id' => isset($payload['document_id']) ? (string) $payload['document_id'] : null,
                'actor_user_id' => is_numeric($payload['actor_user_id'] ?? null)
                    ? (int) $payload['actor_user_id']
                    : null,
                'correlation_id' => $event->getCorrelationId(),
                'occurred_at' => $event->getOccurredAt(),
                'payload' => $payload,
            ]
        );
    }
}
