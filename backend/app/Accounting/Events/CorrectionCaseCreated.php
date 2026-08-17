<?php

namespace App\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CorrectionCaseCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $correctionCaseId,
        public readonly string $caseNumber,
        public readonly string $caseType,
        public readonly string $reasonCode,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
    ) {}
}
