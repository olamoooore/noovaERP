<?php

namespace App\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CorrectionCaseAdvanced
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $correctionCaseId,
        public readonly string $previousState,
        public readonly string $newState,
        public readonly string $childDimension,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
    ) {}
}
