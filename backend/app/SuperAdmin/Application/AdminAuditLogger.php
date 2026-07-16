<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

use App\SuperAdmin\Domain\AdminActionLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AdminAuditLogger
{
    public function __construct(
        private readonly AuditDiffService $diffService,
    ) {}

    /**
     * Record a privileged admin action.
     *
     * @param  string  $action  Dot-notation identifier, e.g. "plan.created"
     * @param  Model|null  $target  The primary entity affected
     * @param  array<mixed>  $before  Snapshot before the action (empty for creates)
     * @param  array<mixed>  $after  Snapshot after the action (empty for deletes)
     * @param  string|null  $reason  Optional operator-supplied reason
     * @param  string  $status  "success" or "failure"
     */
    public function log(
        string $action,
        ?Model $target,
        array $before,
        array $after,
        ?string $reason = null,
        string $status = 'success',
    ): void {
        $actor = Auth::user();
        $targetType = $target ? class_basename($target) : null;
        $targetId = $target?->getKey() !== null ? (string) $target->getKey() : null;

        AdminActionLog::create([
            'admin_user_id' => Auth::id(),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before_snapshot' => $before ?: null,
            'after_snapshot' => $after ?: null,
            'reason' => $reason,
            'ip_address' => Request::ip(),
            'status' => $status,
            'severity' => $this->diffService->severity($action, $status),
            'human_description' => $this->diffService->humanize(
                action: $action,
                actorName: $actor?->name,
                targetType: $targetType,
                targetId: $targetId,
                before: $before,
                after: $after,
            ),
            'created_at' => now(),
        ]);
    }
}
