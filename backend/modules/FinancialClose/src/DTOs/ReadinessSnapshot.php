<?php

namespace Modules\FinancialClose\DTOs;

class ReadinessSnapshot
{
    public function __construct(
        public string $entityId,
        public string $periodId,
        public float $readinessScore,
        public int $totalAccounts,
        public int $reviewedAccounts,
        public int $signedOffAccounts,
        public int $openBlockers,
        public int $openFindings,
        public ?string $generatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'entity_id' => $this->entityId,
            'period_id' => $this->periodId,
            'readiness_score' => $this->readinessScore,
            'total_accounts' => $this->totalAccounts,
            'reviewed_accounts' => $this->reviewedAccounts,
            'signed_off_accounts' => $this->signedOffAccounts,
            'open_blockers' => $this->openBlockers,
            'open_findings' => $this->openFindings,
            'generated_at' => $this->generatedAt,
        ];
    }
}