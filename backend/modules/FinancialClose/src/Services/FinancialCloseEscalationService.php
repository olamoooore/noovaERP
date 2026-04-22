<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\CloseTask;
use Modules\FinancialClose\Models\EscalationEvent;
use Modules\FinancialClose\Models\AuditLog;

class FinancialCloseEscalationService
{
    /**
     * Get all escalation events for a task
     */
    public function listEscalations(string $tenantId, string $taskId): array
    {
        return EscalationEvent::where('tenant_id', $tenantId)
            ->where('task_id', $taskId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Create an escalation event for a task
     */
    public function createEscalation(string $tenantId, string $taskId, string $level, string $reason, ?string $escalatedTo = null): ?EscalationEvent
    {
        $task = CloseTask::where('tenant_id', $tenantId)
            ->where('id', $taskId)
            ->first();

        if (!$task) {
            return null;
        }

        $escalation = EscalationEvent::create([
            'tenant_id' => $tenantId,
            'task_id' => $taskId,
            'escalation_level' => $level,
            'status' => 'open',
            'escalated_to' => $escalatedTo,
            'reason' => $reason,
        ]);

        $task->update(['status' => 'escalated']);

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => 'system',
            'action' => 'task_escalated',
            'entity_type' => 'close_task',
            'entity_id' => $taskId,
            'after' => [
                'escalation_level' => $level,
                'reason' => $reason,
            ],
        ]);

        return $escalation;
    }

    /**
     * Resolve an escalation event
     */
    public function resolveEscalation(string $tenantId, string $escalationId, string $userId): bool
    {
        $escalation = EscalationEvent::where('tenant_id', $tenantId)
            ->where('id', $escalationId)
            ->first();

        if (!$escalation || $escalation->status !== 'open') {
            return false;
        }

        $escalation->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $userId,
            'action' => 'escalation_resolved',
            'entity_type' => 'escalation_event',
            'entity_id' => $escalationId,
        ]);

        return true;
    }

    /**
     * Scan for overdue tasks and create escalations automatically
     */
    public function processOverdueTasks(string $tenantId, string $cycleId): int
    {
        $overdueTasks = CloseTask::where('tenant_id', $tenantId)
            ->where('cycle_id', $cycleId)
            ->whereNotIn('status', ['completed', 'cancelled', 'escalated'])
            ->where('due_date', '<', now())
            ->get();

        $escalated = 0;

        foreach ($overdueTasks as $task) {
            $existing = EscalationEvent::where('tenant_id', $tenantId)
                ->where('task_id', $task->id)
                ->where('status', 'open')
                ->first();

            if (!$existing) {
                $daysOverdue = (int) now()->diffInDays($task->due_date);
                $level       = $this->resolveEscalationLevel($daysOverdue, (bool) $task->is_critical);

                $this->createEscalation(
                    $tenantId,
                    $task->id,
                    $level,
                    "Task overdue by {$daysOverdue} day(s)"
                );
                $escalated++;
            }
        }

        Log::info('FinancialClose: overdue task escalation scan complete', [
            'tenant_id' => $tenantId,
            'cycle_id'  => $cycleId,
            'escalated' => $escalated,
        ]);

        return $escalated;
    }

    /**
     * Determine escalation level from days overdue and criticality.
     * critical tasks escalate faster.
     *   < 1 day:    low
     *   1-2 days:   medium
     *   3-5 days:   high
     *   > 5 days:   critical
     * Critical tasks: bump one level immediately.
     */
    protected function resolveEscalationLevel(int $daysOverdue, bool $isCritical): string
    {
        $level = match(true) {
            $daysOverdue < 1  => 'low',
            $daysOverdue <= 2 => 'medium',
            $daysOverdue <= 5 => 'high',
            default           => 'critical',
        };

        if ($isCritical) {
            $level = match($level) {
                'low'    => 'medium',
                'medium' => 'high',
                'high'   => 'critical',
                default  => 'critical',
            };
        }

        return $level;
    }

    /**
     * Emit a soft-due-date warning for tasks approaching their soft deadline.
     * Does NOT escalate — advisory only. Returns task IDs that are near soft deadline.
     */
    public function getSoftDueDateWarnings(string $tenantId, string $cycleId, int $warningDaysAhead = 1): array
    {
        $threshold = now()->addDays($warningDaysAhead);

        return CloseTask::where('tenant_id', $tenantId)
            ->where('cycle_id', $cycleId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('soft_due_date')
            ->where('soft_due_date', '<=', $threshold)
            ->where('soft_due_date', '>=', now())
            ->get(['id', 'title', 'soft_due_date', 'assigned_to', 'priority'])
            ->toArray();
    }
}
