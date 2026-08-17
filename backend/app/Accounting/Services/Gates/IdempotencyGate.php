<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;

/**
 * Verifies idempotency — same key + same payload = return original result;
 * same key + different payload = reject.
 * ADR-005 §16 — idempotency rules.
 */
class IdempotencyGate implements AuthorityGate
{
    public function gateCode(): string
    {
        return 'IDEMPOTENCY';
    }

    public function gateOwner(): string
    {
        return 'ACC-002';
    }

    public function gateVersion(): string
    {
        return '1.0';
    }

    public function evaluate(array $context): GateEvaluationResult
    {
        $idempotencyKey = $context['idempotency_key'] ?? null;

        if (!$idempotencyKey) {
            // No idempotency key — not applicable (caller must manage)
            return GateEvaluationResult::notApplicable($this->gateCode(), $this->gateOwner());
        }

        // Check if a previous record with this idempotency key exists
        $existingRecord = $context['idempotency_record'] ?? null;

        if ($existingRecord) {
            // A previous record exists — check if payload is identical
            $existingPayloadHash = $existingRecord['payload_hash'] ?? null;
            $currentPayloadHash = $context['payload_hash'] ?? null;

            if ($existingPayloadHash && $currentPayloadHash && $existingPayloadHash !== $currentPayloadHash) {
                return GateEvaluationResult::block(
                    $this->gateCode(),
                    $this->gateOwner(),
                    'IDEMPOTENCY_KEY_REUSE_CONFLICT',
                );
            }

            // Same payload — idempotent replay, return original result
            return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }
}
