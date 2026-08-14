<?php

namespace App\Accounting\Events;

use App\Models\BankReconciliation;
use Illuminate\Foundation\Events\Dispatchable;

class ReconciliationCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly BankReconciliation $reconciliation
    ) {}
}
