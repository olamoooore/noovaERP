<?php

namespace App\Accounting\DTOs;

use App\Accounting\Enums\CommandResult;
use App\Accounting\Enums\ReasonCode;

/**
 * Structured command result.
 * ADR-005 §7 — every command handler must return a structured result.
 */
class CommandResultDTO
{
    public function __construct(
        public readonly CommandResult $result,
        public readonly ?string $aggregateId = null,
        public readonly ?int $recordVersion = null,
        public readonly ?ReasonCode $reasonCode = null,
        public readonly ?string $safeUserMessage = null,
        public readonly ?string $remediationAction = null,
        public readonly array $createdEventIds = [],
        public readonly ?string $commandId = null,
        public readonly ?string $createdAt = null,
    ) {
        $this->createdAt ??= now()->toIso8601String();
    }

    public static function accepted(
        string $aggregateId,
        ?int $recordVersion = null,
        array $createdEventIds = [],
        ?string $commandId = null,
    ): self {
        return new self(
            result: CommandResult::ACCEPTED,
            aggregateId: $aggregateId,
            recordVersion: $recordVersion,
            createdEventIds: $createdEventIds,
            commandId: $commandId,
        );
    }

    public static function rejected(
        ReasonCode $reasonCode,
        ?string $safeUserMessage = null,
        ?string $remediationAction = null,
        ?string $commandId = null,
    ): self {
        return new self(
            result: CommandResult::REJECTED,
            reasonCode: $reasonCode,
            safeUserMessage: $safeUserMessage ?? $reasonCode->label(),
            remediationAction: $remediationAction,
            commandId: $commandId,
        );
    }

    public static function blocked(
        ReasonCode $reasonCode,
        ?string $safeUserMessage = null,
        ?string $remediationAction = null,
        ?string $commandId = null,
    ): self {
        return new self(
            result: CommandResult::BLOCKED,
            reasonCode: $reasonCode,
            safeUserMessage: $safeUserMessage ?? $reasonCode->label(),
            remediationAction: $remediationAction,
            commandId: $commandId,
        );
    }

    public static function conflict(
        ReasonCode $reasonCode = ReasonCode::SOURCE_VERSION_CONFLICT,
        ?string $safeUserMessage = null,
        ?string $commandId = null,
    ): self {
        return new self(
            result: CommandResult::CONFLICT,
            reasonCode: $reasonCode,
            safeUserMessage: $safeUserMessage ?? 'The record has been modified by another operation. Please refresh and retry.',
            commandId: $commandId,
        );
    }

    public static function failed(
        string $error,
        ?string $commandId = null,
    ): self {
        return new self(
            result: CommandResult::FAILED,
            safeUserMessage: $error,
            commandId: $commandId,
        );
    }

    public function isSuccess(): bool
    {
        return $this->result === CommandResult::ACCEPTED;
    }

    public function toArray(): array
    {
        return [
            'result' => $this->result->value,
            'aggregate_id' => $this->aggregateId,
            'record_version' => $this->recordVersion,
            'reason_code' => $this->reasonCode?->value,
            'safe_user_message' => $this->safeUserMessage,
            'remediation_action' => $this->remediationAction,
            'created_event_ids' => $this->createdEventIds,
            'command_id' => $this->commandId,
            'created_at' => $this->createdAt,
        ];
    }
}
