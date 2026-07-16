<?php

namespace Modules\FinancialClose\DTOs;

class PolicyDecision
{
    public function __construct(
        public bool $allowed,
        public string $reason,
        public ?string $requiredRole,
        public ?string $blockedBy,
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason,
            'required_role' => $this->requiredRole,
            'blocked_by' => $this->blockedBy,
            'metadata' => $this->metadata,
        ];
    }
}
