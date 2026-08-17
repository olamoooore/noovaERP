<?php

namespace App\Accounting\DTOs;

use App\Accounting\Enums\ReasonCode;

/**
 * Canonical negative-state contract.
 * ADR-005 §29 — every material negative state must retain reason and remediation data.
 */
class NegativeState
{
    public function __construct(
        public readonly string $state,
        public readonly ReasonCode $reasonCode,
        public readonly ?string $reasonCategory = null,
        public readonly ?string $safeUserMessage = null,
        public readonly ?string $remediationAction = null,
        public readonly ?string $blockingReference = null,
        public readonly ?string $occurredAt = null,
        public readonly ?string $resolvedAt = null,
        public readonly array $metadata = [],
    ) {
        $this->reasonCategory ??= $this->reasonCode->category();
    }

    public static function of(
        string $state,
        ReasonCode $reasonCode,
        ?string $safeUserMessage = null,
        ?string $remediationAction = null,
        ?string $blockingReference = null,
    ): self {
        return new self(
            state: $state,
            reasonCode: $reasonCode,
            safeUserMessage: $safeUserMessage,
            remediationAction: $remediationAction,
            blockingReference: $blockingReference,
        );
    }

    public function resolve(?string $resolvedAt = null): self
    {
        return new self(
            state: $this->state,
            reasonCode: $this->reasonCode,
            reasonCategory: $this->reasonCategory,
            safeUserMessage: $this->safeUserMessage,
            remediationAction: $this->remediationAction,
            blockingReference: $this->blockingReference,
            occurredAt: $this->occurredAt,
            resolvedAt: $resolvedAt ?? now()->toIso8601String(),
            metadata: $this->metadata,
        );
    }

    public function isResolved(): bool
    {
        return $this->resolvedAt !== null;
    }

    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'reason_code' => $this->reasonCode->value,
            'reason_category' => $this->reasonCategory,
            'safe_user_message' => $this->safeUserMessage,
            'remediation_action' => $this->remediationAction,
            'blocking_reference' => $this->blockingReference,
            'occurred_at' => $this->occurredAt,
            'resolved_at' => $this->resolvedAt,
        ];
    }
}
