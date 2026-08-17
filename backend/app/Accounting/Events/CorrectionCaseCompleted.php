<?php

namespace App\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CorrectionCaseCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $correctionCaseId,
        public readonly string $caseNumber,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
    ) {}
}
