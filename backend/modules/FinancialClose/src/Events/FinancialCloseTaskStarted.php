<?php

namespace Modules\FinancialClose\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FinancialCloseTaskStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $taskId,
        public readonly string $userId,
    ) {}
}
