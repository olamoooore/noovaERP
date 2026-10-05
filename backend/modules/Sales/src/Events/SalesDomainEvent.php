<?php

namespace Modules\Sales\Events;

use App\Platform\EventBus\Contracts\DomainEventContract;
use Modules\Sales\Models\SalesEventOutboxEvent;

/**
 * SalesDomainEvent — a versioned Sales event published on the platform Domain
 * Event Bus (issue #901, PC7).
 *
 * Instances are reconstructed by the `sales:publish-outbox` publisher from a
 * durable sales_event_outbox row, so the event id, correlation id and
 * occurred_at survive publisher retries and every redelivery carries the same
 * `payload.event_id` — the consumer-side idempotency key.
 */
class SalesDomainEvent implements DomainEventContract
{
    public function __construct(
        private readonly string $eventName,
        private readonly string $version,
        private readonly string $tenantId,
        private readonly ?string $userId,
        private readonly string $correlationId,
        private readonly string $occurredAt,
        private readonly array $payload,
        private readonly bool $idempotent = true,
        private readonly string $sourceModule = 'sales',
    ) {}

    public static function fromOutbox(SalesEventOutboxEvent $row): self
    {
        return new self(
            eventName: $row->event_name,
            version: (string) $row->version,
            tenantId: (string) $row->tenant_id,
            userId: $row->actor_user_id !== null ? (string) $row->actor_user_id : null,
            correlationId: (string) $row->correlation_id,
            occurredAt: $row->occurred_at?->toIso8601String() ?? now()->toIso8601String(),
            payload: $row->payload ?? [],
        );
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getOccurredAt(): string
    {
        return $this->occurredAt;
    }

    public function getSourceModule(): string
    {
        return $this->sourceModule;
    }

    public function isIdempotent(): bool
    {
        return $this->idempotent;
    }

    public function toArray(): array
    {
        return [
            'event_name' => $this->getEventName(),
            'version' => $this->getVersion(),
            'tenant_id' => $this->getTenantId(),
            'user_id' => $this->getUserId(),
            'correlation_id' => $this->getCorrelationId(),
            'source_module' => $this->getSourceModule(),
            'occurred_at' => $this->getOccurredAt(),
            'idempotent' => $this->isIdempotent(),
            'payload' => $this->getPayload(),
        ];
    }
}
