<?php

namespace App\Platform\Audit;

/**
 * Issue #912: outcome of walking a tenant's audit hash chain.
 */
final class AuditChainVerificationResult
{
    /**
     * @param  list<array{type: string, source: string, sequence: int|null, entry_id: string|int|null, message: string}>  $failures
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $checked,
        public readonly int $legacySkipped,
        public readonly array $failures = [],
        public readonly ?int $headSequence = null,
        public readonly int $anchorsChecked = 0,
    ) {}

    public function firstFailure(): ?array
    {
        return $this->failures[0] ?? null;
    }
}
