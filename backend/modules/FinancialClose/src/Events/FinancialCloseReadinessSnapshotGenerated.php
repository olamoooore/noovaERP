<?php

namespace Modules\FinancialClose\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FinancialCloseReadinessSnapshotGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $entityId,
        public readonly string $periodId,
        public readonly float  $readinessScore,
        public readonly int    $openBlockers,
    ) {}
}
