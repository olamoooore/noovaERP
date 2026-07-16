<?php

namespace Modules\Contact\src\Events;

use App\Platform\EventBus\Contracts\DomainEventContract;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Base event for all Contact Master events.
 * Implements DomainEventContract for the platform Domain Event Bus.
 */
abstract class ContactEvent implements DomainEventContract
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    protected string $eventName;

    protected string $version = '1.0.0';

    protected string $correlationId;

    protected string $occurredAt;

    protected bool $idempotent = true;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $contactId,
        public readonly ?string $userId = null,
        public readonly ?array $oldValue = null,
        public readonly ?array $newValue = null,
    ) {
        $this->correlationId = Str::uuid()->toString();
        $this->occurredAt = now()->toIso8601String();
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

    public function getOccurredAt(): string
    {
        return $this->occurredAt;
    }

    public function getSourceModule(): string
    {
        return 'contact';
    }

    public function isIdempotent(): bool
    {
        return $this->idempotent;
    }

    public function getPayload(): array
    {
        return [
            'contact_id' => $this->contactId,
            'old_value' => $this->oldValue,
            'new_value' => $this->newValue,
        ];
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
