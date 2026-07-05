<?php

namespace App\Platform\EventBus\Events;

use App\Platform\EventBus\Contracts\DomainEventContract;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Base DomainEvent — all cross-module events extend this.
 *
 * Provides standard metadata (tenant, user, correlation, timestamp)
 * and ensures consistent event shape for the Domain Event Bus.
 */
abstract class DomainEvent implements DomainEventContract
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    protected string $eventName;
    protected string $version = '1.0.0';
    protected string $tenantId;
    protected ?string $userId = null;
    protected string $correlationId;
    protected string $occurredAt;
    protected string $sourceModule;
    protected bool $idempotent = true;

    public function __construct(
        string $tenantId,
        ?string $userId = null,
        ?string $correlationId = null,
    ) {
        $this->tenantId = $tenantId;
        $this->userId = $userId;
        $this->correlationId = $correlationId ?? Str::uuid()->toString();
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
        return $this->sourceModule;
    }

    public function isIdempotent(): bool
    {
        return $this->idempotent;
    }

    /**
     * Convert event to array for storage/dispatch.
     */
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
