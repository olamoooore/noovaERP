<?php

namespace Modules\FinancialClose\DTOs;

use Modules\FinancialClose\Enums\FindingStatus;
use Modules\FinancialClose\Enums\RiskStatus;

class FindingSummary
{
    public function __construct(
        public string $id,
        public string $accountCode,
        public string $description,
        public FindingStatus $status,
        public RiskStatus $riskStatus,
        public float $confidence,
        public ?string $acceptedBy,
        public ?string $resolvedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'account_code' => $this->accountCode,
            'description' => $this->description,
            'status' => $this->status->value,
            'risk_status' => $this->riskStatus->value,
            'confidence' => $this->confidence,
            'accepted_by' => $this->acceptedBy,
            'resolved_at' => $this->resolvedAt,
        ];
    }
}