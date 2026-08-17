<?php

namespace App\Accounting\DTOs;

use App\Accounting\Enums\CommandResult;
use App\Accounting\Enums\ReasonCode;
use Illuminate\Support\Str;

/**
 * Canonical command envelope.
 * ADR-005 §7 — all material Finance commands must carry a canonical envelope.
 */
class CommandEnvelope
{
    public function __construct(
        public readonly string $commandType,
        public readonly string $tenantId,
        public readonly string $entityId,
        public readonly string $actorId,
        public readonly string $actorType = 'HUMAN',
        public readonly ?string $commandId = null,
        public readonly string $commandVersion = '1.0',
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $causationId = null,
        public readonly ?string $aggregateType = null,
        public readonly ?string $aggregateId = null,
        public readonly ?int $expectedRecordVersion = null,
        public readonly ?string $sourceCapability = null,
        public readonly ?string $sourceReference = null,
        public readonly ?string $requestedAt = null,
        public readonly array $payload = [],
    ) {
        $this->requestedAt ??= now()->toIso8601String();
    }

    public static function forAggregate(
        string $commandType,
        string $aggregateType,
        string $aggregateId,
        string $tenantId,
        string $entityId,
        string $actorId,
        array $payload = [],
        ?string $actorType = null,
        ?int $expectedRecordVersion = null,
        ?string $idempotencyKey = null,
        ?string $correlationId = null,
        ?string $sourceCapability = null,
    ): self {
        return new self(
            commandType: $commandType,
            tenantId: $tenantId,
            entityId: $entityId,
            actorId: $actorId,
            actorType: $actorType ?? 'HUMAN',
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            expectedRecordVersion: $expectedRecordVersion,
            idempotencyKey: $idempotencyKey ?? Str::uuid()->toString(),
            correlationId: $correlationId ?? Str::uuid()->toString(),
            sourceCapability: $sourceCapability,
            payload: $payload,
        );
    }

    public function toAuditArray(): array
    {
        return [
            'command_id' => $this->commandId,
            'command_type' => $this->commandType,
            'command_version' => $this->commandVersion,
            'idempotency_key' => $this->idempotencyKey,
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
            'tenant_id' => $this->tenantId,
            'entity_id' => $this->entityId,
            'actor_id' => $this->actorId,
            'actor_type' => $this->actorType,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'expected_record_version' => $this->expectedRecordVersion,
            'source_capability' => $this->sourceCapability,
            'source_reference' => $this->sourceReference,
            'requested_at' => $this->requestedAt,
        ];
    }
}
