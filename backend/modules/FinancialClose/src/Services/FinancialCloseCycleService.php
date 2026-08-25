<?php

namespace Modules\FinancialClose\Services;

use App\Models\FiscalPeriod;
use App\Models\LockDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Events\FinancialCloseCycleOpened;
use Modules\FinancialClose\Models\AuditLog;
use Modules\FinancialClose\Models\CloseCycle;
use Modules\FinancialClose\Models\CloseTask;

class FinancialCloseCycleService
{
    /**
     * Roles authorised to sign off a close cycle (final approval gate).
     */
    private const SIGNOFF_ROLES = ['Owner', 'Admin', 'Manager'];

    /**
     * Minimum number of distinct sign-offs required to mark a cycle ready to close.
     */
    private const REQUIRED_SIGNOFFS = 1;

    /**
     * Get all close cycles for a tenant/entity
     */
    public function listCycles(string $tenantId, string $entityId, array $filters = []): array
    {
        $query = CloseCycle::where('tenant_id', $tenantId);

        if ($entityId !== '') {
            $query->where('entity_id', $entityId);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('created_at', 'desc')->get()->toArray();
    }

    /**
     * Get a single close cycle
     */
    public function getCycle(string $tenantId, string $cycleId): ?CloseCycle
    {
        return CloseCycle::where('tenant_id', $tenantId)
            ->where('id', $cycleId)
            ->first();
    }

    /**
     * Create a new close cycle
     */
    public function createCycle(
        string $tenantId,
        string $entityId,
        string $periodId,
        string $userId,
        ?string $templateId = null,
        ?string $name = null,
        ?string $accountingBookId = null,
    ): ?CloseCycle {
        $existing = CloseCycle::where('tenant_id', $tenantId)
            ->where('entity_id', $entityId)
            ->where('period_id', $periodId)
            ->whereNotIn('status', ['closed', 'archived'])
            ->first();

        if ($existing) {
            Log::warning('FinancialClose: active cycle already exists for period', [
                'tenant_id' => $tenantId,
                'entity_id' => $entityId,
                'period_id' => $periodId,
            ]);

            return null;
        }

        return CloseCycle::create([
            'tenant_id' => $tenantId,
            'entity_id' => $entityId,
            'accounting_book_id' => $accountingBookId,
            'period_id' => $periodId,
            'name' => $name,
            'template_id' => $templateId,
            'status' => 'draft',
            'created_by' => $userId,
        ]);
    }

    /**
     * Open a close cycle
     */
    public function openCycle(string $tenantId, string $cycleId, string $userId): bool
    {
        $cycle = $this->getCycle($tenantId, $cycleId);

        if (! $cycle || $cycle->status !== 'draft') {
            return false;
        }

        $cycle->update([
            'status' => 'opened',
            'opened_at' => now(),
        ]);

        event(new FinancialCloseCycleOpened(
            $tenantId,
            $cycleId,
            $cycle->entity_id,
            $cycle->period_id,
            $userId,
        ));

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $userId,
            'action' => 'cycle_opened',
            'entity_type' => 'close_cycle',
            'entity_id' => $cycleId,
        ]);

        return true;
    }

    /**
     * Close a cycle — also locks the fiscal period and creates a lock_date record
     */
    public function closeCycle(string $tenantId, string $cycleId, string $userId): bool
    {
        $cycle = $this->getCycle($tenantId, $cycleId);

        if (! $cycle || $cycle->status !== 'ready_to_close') {
            Log::warning('FinancialClose: cycle not ready to close', [
                'cycle_id' => $cycleId,
                'status' => $cycle?->status,
            ]);

            return false;
        }

        $cycle->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        // ── Lock the fiscal period ──────────────────────────
        $periodCode = $cycle->period_id;
        $period = FiscalPeriod::where('code', $periodCode)->first();

        if ($period && ! $period->isLocked()) {
            $period->lock();

            Log::info('FinancialClose: fiscal period locked on cycle close', [
                'period_code' => $periodCode,
                'cycle_id' => $cycleId,
            ]);
        }

        // ── Also create a lock_date record for transaction-type enforcement ──
        // Use the period end date as the lock date (last day of the period)
        $lockDateValue = $period?->period_end ?? now();
        if ($period) {
            // Deactivate any existing 'all' lock_date that's older
            LockDate::where('lock_type', 'all')
                ->where('is_active', true)
                ->where('lock_date', '<', $lockDateValue)
                ->update(['is_active' => false]);

            // Create or update the lock_date record
            $existingLock = LockDate::where('lock_type', 'all')
                ->where('lock_date', $lockDateValue)
                ->first();

            if (! $existingLock) {
                LockDate::create([
                    'lock_type' => 'all',
                    'lock_date' => $lockDateValue,
                    'set_by' => $userId,
                    'reason' => "Financial Close cycle {$cycleId} closed for period {$periodCode}",
                    'is_active' => true,
                ]);
            }
        }

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $userId,
            'action' => 'cycle_closed',
            'entity_type' => 'close_cycle',
            'entity_id' => $cycleId,
            'after' => [
                'period_locked' => $period?->code,
                'lock_date' => $lockDateValue instanceof \Carbon\Carbon
                    ? $lockDateValue->format('Y-m-d')
                    : $lockDateValue,
            ],
        ]);

        return true;
    }

    /**
     * Record a cycle-level sign-off from an authorised user.
     * A cycle must have at least REQUIRED_SIGNOFFS distinct authorised
     * sign-offs AND all tasks completed/resolved before it can be closed.
     *
     * Role is resolved server-side from the user's actual roles — the client-submitted
     * role parameter is ignored to prevent role-spoofing (ADR-005 §41, G22).
     */
    public function signoffCycle(string $tenantId, string $cycleId, string $userId, ?string $role, ?string $notes = null): array
    {
        $cycle = $this->getCycle($tenantId, $cycleId);

        if (! $cycle) {
            return ['success' => false, 'reason' => 'Cycle not found.'];
        }

        if (in_array($cycle->status, ['closed', 'archived'])) {
            return ['success' => false, 'reason' => 'Cycle is already closed.'];
        }

        // Block sign-off if there are incomplete tasks
        $incompleteTasks = CloseTask::where('tenant_id', $tenantId)
            ->where('cycle_id', $cycleId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();

        if ($incompleteTasks > 0) {
            return [
                'success' => false,
                'reason' => "Cannot sign off: {$incompleteTasks} incomplete task(s) must be completed or cancelled first.",
                'incomplete_tasks' => $incompleteTasks,
            ];
        }

        // Block sign-off if there are open escalations
        $openEscalations = \Modules\FinancialClose\Models\EscalationEvent::where('tenant_id', $tenantId)
            ->whereHas('task', function ($q) use ($cycleId) {
                $q->where('cycle_id', $cycleId);
            })
            ->where('status', 'open')
            ->count();

        if ($openEscalations > 0) {
            return [
                'success' => false,
                'reason' => "Cannot sign off: {$openEscalations} open escalation(s) must be resolved first.",
                'open_escalations' => $openEscalations,
            ];
        }

        // ── Resolve role server-side (ADR-005 §41, G22) ──────────────────
        // Never trust client-submitted role — resolve from actual user roles
        $resolvedRole = $this->resolveUserRole($userId);
        if (! $resolvedRole || ! in_array($resolvedRole, self::SIGNOFF_ROLES)) {
            return [
                'success' => false,
                'reason' => 'Insufficient role for sign-off. Required roles: ' . implode(', ', self::SIGNOFF_ROLES) . '. Your role: ' . ($resolvedRole ?? 'none'),
            ];
        }

        $metadata = is_array($cycle->metadata) ? $cycle->metadata : [];
        $signoffs = $metadata['cycle_signoffs'] ?? [];

        // Prevent duplicate sign-off by the same user
        $alreadySigned = collect($signoffs)->firstWhere('user_id', $userId);
        if ($alreadySigned) {
            return ['success' => false, 'reason' => 'You have already signed off this cycle.'];
        }

        $signoffs[] = [
            'user_id' => $userId,
            'role' => $resolvedRole,
            'notes' => $notes,
            'signed_at' => now()->toIso8601String(),
        ];

        $metadata['cycle_signoffs'] = $signoffs;
        $cycle->update(['metadata' => $metadata]);

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $userId,
            'action' => 'cycle_signed_off',
            'entity_type' => 'close_cycle',
            'entity_id' => $cycleId,
            'after' => ['role' => $resolvedRole, 'signoff_count' => count($signoffs)],
        ]);

        // Re-evaluate readiness after each sign-off
        $evaluation = $this->evaluateReadiness($tenantId, $cycleId);

        return [
            'success' => true,
            'signoff_count' => count($signoffs),
            'ready_to_close' => $evaluation['ready_to_close'],
            'evaluation' => $evaluation,
        ];
    }

    /**
     * Resolve the user's actual role from their assigned roles (server-side).
     * Returns null if user has no authorised sign-off role.
     */
    private function resolveUserRole(string $userId): ?string
    {
        $user = \App\Models\User::find($userId);
        if (! $user) {
            return null;
        }

        // Check Spatie role names
        if (method_exists($user, 'getRoleNames')) {
            try {
                $roles = $user->getRoleNames();
                foreach ($roles as $role) {
                    if (in_array($role, self::SIGNOFF_ROLES)) {
                        return $role;
                    }
                }
            } catch (\Throwable) {
                // Spatie not configured
            }
        }

        // Fallback: check the 'role' column on users table
        if ($user->role && in_array($user->role, self::SIGNOFF_ROLES)) {
            return $user->role;
        }

        return null;
    }

    /**
     * Revoke a cycle-level sign-off.
     */
    public function revokeCycleSignoff(string $tenantId, string $cycleId, string $userId): array
    {
        $cycle = $this->getCycle($tenantId, $cycleId);

        if (! $cycle) {
            return ['success' => false, 'reason' => 'Cycle not found.'];
        }

        $metadata = is_array($cycle->metadata) ? $cycle->metadata : [];
        $signoffs = $metadata['cycle_signoffs'] ?? [];

        $filtered = array_values(array_filter($signoffs, fn ($s) => $s['user_id'] !== $userId));

        if (count($filtered) === count($signoffs)) {
            return ['success' => false, 'reason' => 'No sign-off found for this user.'];
        }

        $metadata['cycle_signoffs'] = $filtered;
        $cycle->update(['metadata' => $metadata]);

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $userId,
            'action' => 'cycle_signoff_revoked',
            'entity_type' => 'close_cycle',
            'entity_id' => $cycleId,
        ]);

        // Downgrade from ready_to_close if sign-offs no longer sufficient
        if ($cycle->status === 'ready_to_close') {
            $cycle->update(['status' => 'opened']);
        }

        return ['success' => true];
    }

    /**
     * Evaluate whether a cycle meets all conditions to be marked ready_to_close.
     *
     * Conditions:
     *  - All tasks are completed or cancelled (no open/in-progress/submitted/blocked/escalated)
     *  - No open escalation events
     *  - No open assurance findings that block closure (ADR-005 §39.1)
     *  - At least REQUIRED_SIGNOFFS cycle-level sign-offs from authorised roles
     */
    public function evaluateReadiness(string $tenantId, string $cycleId): array
    {
        $cycle = $this->getCycle($tenantId, $cycleId);

        if (! $cycle) {
            return ['ready_to_close' => false, 'reason' => 'Cycle not found.'];
        }

        // Count incomplete tasks
        $incompleteTasks = CloseTask::where('tenant_id', $tenantId)
            ->where('cycle_id', $cycleId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();

        // Count open escalations
        $openEscalations = \Modules\FinancialClose\Models\EscalationEvent::where('tenant_id', $tenantId)
            ->whereHas('task', function ($q) use ($cycleId) {
                $q->where('cycle_id', $cycleId);
            })
            ->where('status', 'open')
            ->count();

        // ── Blocking-policy-aware open findings check (ADR-005 §39.1, #39) ──
        // A finding blocks closure when:
        //   - its status is `open` or `investigating` (canonical unresolved states),
        //   - AND its severity is `high` or `critical`,
        //   - AND it is either tied to this cycle (source_type=close_cycle,
        //     source_id=cycleId) OR it is an entity-wide open finding for the
        //     cycle's entity.
        //
        // This policy is the documented contract: medium/low findings remain
        // visible but do not by themselves block closure; an admin may override.
        $blockingFindings = \App\Models\Accounting\AccountingFinding::where('tenant_id', $tenantId)
            ->where(function ($q) use ($cycleId, $cycle) {
                $q->where(function ($inner) use ($cycleId) {
                    $inner->where('source_type', 'close_cycle')
                        ->where('source_id', $cycleId);
                })->orWhere('entity_id', $cycle->entity_id);
            })
            ->whereIn('status', ['open', 'investigating'])
            ->whereIn('severity', ['high', 'critical'])
            ->count();

        // Total open findings (for reporting visibility, not blocking):
        $totalOpenFindings = \App\Models\Accounting\AccountingFinding::where('tenant_id', $tenantId)
            ->where(function ($q) use ($cycleId, $cycle) {
                $q->where(function ($inner) use ($cycleId) {
                    $inner->where('source_type', 'close_cycle')
                        ->where('source_id', $cycleId);
                })->orWhere('entity_id', $cycle->entity_id);
            })
            ->whereIn('status', ['open', 'investigating'])
            ->count();

        // Count authorised sign-offs
        $metadata = is_array($cycle->metadata) ? $cycle->metadata : [];
        $signoffs = $metadata['cycle_signoffs'] ?? [];
        $authorisedSignoffs = collect($signoffs)
            ->filter(fn ($s) => in_array($s['role'], self::SIGNOFF_ROLES))
            ->count();

        $ready = $incompleteTasks === 0
            && $openEscalations === 0
            && $blockingFindings === 0
            && $authorisedSignoffs >= self::REQUIRED_SIGNOFFS;

        // Auto-transition to ready_to_close when conditions are met
        if ($ready && $cycle->status === 'opened') {
            $cycle->update(['status' => 'ready_to_close']);

            AuditLog::create([
                'tenant_id' => $tenantId,
                'actor_id' => 'system',
                'action' => 'cycle_ready_to_close',
                'entity_type' => 'close_cycle',
                'entity_id' => $cycleId,
                'after' => [
                    'incomplete_tasks' => $incompleteTasks,
                    'open_escalations' => $openEscalations,
                    'blocking_findings' => $blockingFindings,
                    'open_findings' => $totalOpenFindings,
                    'signoffs' => $authorisedSignoffs,
                ],
            ]);
        }

        // Downgrade if conditions no longer met
        if (! $ready && $cycle->status === 'ready_to_close') {
            $cycle->update(['status' => 'opened']);
        }

        return [
            'ready_to_close' => $ready,
            'incomplete_tasks' => $incompleteTasks,
            'open_escalations' => $openEscalations,
            // #39: split blocking from non-blocking findings; expose the policy.
            'blocking_findings' => $blockingFindings,
            'open_findings' => $totalOpenFindings,
            'blocking_policy' => [
                'severity_threshold' => 'high_or_critical',
                'unresolved_statuses' => ['open', 'investigating'],
                'scope' => 'cycle_or_entity',
            ],
            'authorised_signoffs' => $authorisedSignoffs,
            'required_signoffs' => self::REQUIRED_SIGNOFFS,
        ];
    }

    /**
     * Reopen a locked fiscal period.
     * Requires authorised role (Admin/Owner/Manager) and logs an audit trail.
     * Also rolls back the company_settings.lock_date to the previous period's end.
     */
    public function reopenPeriod(string $tenantId, string $periodCode, string $userId, ?string $role = null): array
    {
        $period = FiscalPeriod::where('code', $periodCode)->first();

        if (! $period) {
            return ['success' => false, 'reason' => "Fiscal period '{$periodCode}' not found."];
        }

        if (! $period->isLocked()) {
            // Also check company_settings.lock_date — if it covers this period, treat as locked
            $companyLockDate = DB::table('company_settings')->whereNotNull('lock_date')->value('lock_date');
            if (! $companyLockDate || ! $period->period_start->lte(\Carbon\Carbon::parse($companyLockDate))) {
                return ['success' => false, 'reason' => "Fiscal period '{$periodCode}' is not locked."];
            }
        }

        // Authorisation check: only Admin/Owner/Manager can reopen
        $isAuthorised = false;
        $user = \App\Models\User::find($userId);

        if ($user) {
            // 1. Check Spatie role names (Owner, Admin, Manager)
            if (method_exists($user, 'hasRole')) {
                try {
                    $isAuthorised = $user->hasRole(['Owner', 'Admin', 'Manager', 'super-admin']);
                } catch (\Throwable $e) {
                    // Spatie not fully configured
                }
            }

            // 2. Check accounting.settings permission
            if (! $isAuthorised && method_exists($user, 'hasPermissionTo')) {
                try {
                    $isAuthorised = $user->hasPermissionTo('accounting.settings');
                } catch (\Throwable $e) {
                    // Permission not found
                }
            }
        }

        if (! $isAuthorised) {
            return ['success' => false, 'reason' => 'Insufficient permissions. Only Admin/Owner/Manager can reopen a locked period.'];
        }

        // Unlock the fiscal period
        if ($period->isLocked()) {
            $period->unlock();
        }

        // Deactivate the matching lock_date record
        LockDate::where('lock_type', 'all')
            ->where('is_active', true)
            ->where('lock_date', '<=', $period->period_end)
            ->update(['is_active' => false]);

        // Roll back company_settings.lock_date to previous period's end
        $prevPeriod = FiscalPeriod::where('period_end', '<', $period->period_start)
            ->orderBy('period_end', 'desc')
            ->first();

        $newLockDate = $prevPeriod?->period_end?->format('Y-m-d') ?? null;
        DB::table('company_settings')->update(['lock_date' => $newLockDate]);

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $userId,
            'action' => 'period_reopened',
            'entity_type' => 'fiscal_period',
            'entity_id' => (string) $period->id,
            'after' => [
                'period_code' => $periodCode,
                'role' => $role,
                'new_lock_date' => $newLockDate,
            ],
        ]);

        Log::info('FinancialClose: fiscal period reopened', [
            'period_code' => $periodCode,
            'user_id' => $userId,
            'role' => $role,
            'new_lock_date' => $newLockDate,
        ]);

        return [
            'success' => true,
            'message' => "Period '{$periodCode}' has been reopened.",
            'lock_date' => $newLockDate,
        ];
    }
}
