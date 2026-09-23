<?php

namespace App\Accounting\Events;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class ApprovalDecisionEvent
{
    use Dispatchable;

    public const ACTION_APPROVED = 'approved';

    public const ACTION_REJECTED = 'rejected';

    public const ACTION_EXPIRED = 'expired';

    public const ACTION_WITHDRAWN = 'withdrawn';

    public function __construct(
        public readonly ApprovalRequest $approvalRequest,
        public readonly string $action,
        public readonly ?User $actor = null,
        public readonly ?string $reason = null
    ) {}
}
