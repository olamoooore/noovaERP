<?php

namespace App\Accounting\DTOs;

use App\Accounting\Enums\GateResult;

/**
 * Result of a single gate evaluation.
 * ADR-005 §8.2 — gate result schema.
 */
class GateEvaluationResult
{
    public function __construct(
        public readonly string $gateCode,
        public readonly string $gateOwner,
        public readonly ?string $gateVersion = null,
        public readonly GateResult $result = GateResult::PASS,
        public readonly ?string $reasonCode = null,
        public readonly ?string $evidenceReference = null,
        public readonly ?string $policyReference = null,
        public readonly ?string $stateVersion = null,
        public readonly ?string $evaluatedAt = null,
    ) {
        $this->evaluatedAt ??= now()->toIso8601String();
    }

    public static function pass(string $gateCode, string $gateOwner, ?string $gateVersion = null): self
    {
        return new self(
            gateCode: $gateCode,
            gateOwner: $gateOwner,
            gateVersion: $gateVersion,
            result: GateResult::PASS,
        );
    }

    public static function block(
        string $gateCode,
        string $gateOwner,
        string $reasonCode,
        ?string $gateVersion = null,
        ?string $evidenceReference = null,
    ): self {
        return new self(
            gateCode: $gateCode,
            gateOwner: $gateOwner,
            gateVersion: $gateVersion,
            result: GateResult::BLOCK,
            reasonCode: $reasonCode,
            evidenceReference: $evidenceReference,
        );
    }

    public static function notApplicable(string $gateCode, string $gateOwner): self
    {
        return new self(
            gateCode: $gateCode,
            gateOwner: $gateOwner,
            result: GateResult::NOT_APPLICABLE,
        );
    }

    public static function error(string $gateCode, string $gateOwner, ?string $reasonCode = null): self
    {
        return new self(
            gateCode: $gateCode,
            gateOwner: $gateOwner,
            result: GateResult::ERROR,
            reasonCode: $reasonCode,
        );
    }

    public function toArray(): array
    {
        return [
            'gate_code' => $this->gateCode,
            'gate_owner' => $this->gateOwner,
            'gate_version' => $this->gateVersion,
            'result' => $this->result->value,
            'reason_code' => $this->reasonCode,
            'evidence_reference' => $this->evidenceReference,
            'policy_reference' => $this->policyReference,
            'state_version' => $this->stateVersion,
            'evaluated_at' => $this->evaluatedAt,
        ];
    }
}
