<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

use App\SuperAdmin\Domain\AdminActionLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AdminAuditLogger
{
    /**
     * Record a privileged admin action.
     *
     * @param string       $action         Dot-notation identifier, e.g. "plan.created"
     * @param Model|null   $target         The primary entity affected (nullable for system actions)
     * @param array<mixed> $before         Snapshot of state before the action (empty for creates)
     * @param array<mixed> $after          Snapshot of state after the action (empty for deletes)
     * @param string|null  $reason         Optional reason supplied by the operator
     * @param string       $status         "success" or "failure"
     */
    public function log(
        string  $action,
        ?Model  $target,
        array   $before,
        array   $after,
        ?string $reason = null,
        string  $status = 'success',
    ): void {
        AdminActionLog::create([
            'admin_user_id'   => Auth::id(),
            'action'          => $action,
            'target_type'     => $target ? class_basename($target) : null,
            'target_id'       => $target?->getKey() !== null ? (string) $target->getKey() : null,
            'before_snapshot' => $before ?: null,
            'after_snapshot'  => $after ?: null,
            'reason'          => $reason,
            'ip_address'      => Request::ip(),
            'status'          => $status,
            'created_at'      => now(),
        ]);
    }
}
