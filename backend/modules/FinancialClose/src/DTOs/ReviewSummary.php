<?php

namespace Modules\FinancialClose\DTOs;

use Modules\FinancialClose\Enums\ReviewStatus;
use Modules\FinancialClose\Enums\RiskStatus;

class ReviewSummary
{
    public function __construct(
        public string $id,
        public string $accountCode,
        public string $accountName,
        public ReviewStatus $status,
        public RiskStatus $riskStatus,
        public ?string $assignedTo,
        public ?string $dueDate,
        public bool $hasEvidence,
        public bool $isSigned,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'account_code' => $this->accountCode,
            'account_name' => $this->accountName,
            'status' => $this->status->value,
            'risk_status' => $this->riskStatus->value,
            'assigned_to' => $this->assignedTo,
            'due_date' => $this->dueDate,
            'has_evidence' => $this->hasEvidence,
            'is_signed' => $this->isSigned,
        ];
    }
}
