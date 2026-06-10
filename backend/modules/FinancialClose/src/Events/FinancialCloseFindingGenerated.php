<?php

namespace Modules\FinancialClose\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an AI finding is successfully persisted.
 * Listener can trigger readiness refresh when high/critical risk findings are generated.
 */
class FinancialCloseFindingGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $findingId,
        public readonly ?string $reviewId,
        public readonly string $riskStatus,
    ) {}
}
