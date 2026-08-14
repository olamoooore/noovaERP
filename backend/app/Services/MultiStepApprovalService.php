<?php

namespace App\Services;

use App\Models\Accounting\ApprovalInstance;
use App\Models\Accounting\ApprovalStepInstance;
use App\Models\Accounting\ApprovalWorkflow;
use App\Models\Accounting\ApprovalWorkflowStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MultiStepApprovalService
{
    /**
     * Initiate a multi-step approval workflow.
     */
    public function initiate(
        string $workflowCode,
        string $entityType,
        string $entityId,
        float $amount,
        string $initiatedBy,
        ?string $reason = null,
        ?array $metadata = null
    ): ApprovalInstance {
        return DB::transaction(function () use ($workflowCode, $entityType, $entityId, $amount, $initiatedBy, $reason, $metadata) {
            $workflow = ApprovalWorkflow::where('tenant_id', tenant()->id)
                ->where('code', $workflowCode)
                ->where('is_active', true)
                ->firstOrFail();

            // Check for existing active instance
            $existing = ApprovalInstance::where('tenant_id', tenant()->id)
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->whereIn('status', ['pending', 'in_progress'])
                ->first();

            if ($existing) {
                throw new \DomainException('APPROVAL_ALREADY_IN_PROGRESS');
            }

            $steps = $workflow->getOrderedSteps()->filter(function ($step) use ($amount) {
                return $step->isRequiredForAmount($amount);
            })->values();

            if ($steps->isEmpty()) {
                throw new \DomainException('NO_APPROVAL_STEPS_DEFINED');
            }

            $instance = ApprovalInstance::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => tenant()->id,
                'workflow_id' => $workflow->id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'status' => 'pending',
                'current_step' => $steps->first()->step_order,
                'total_steps' => $steps->count(),
                'amount' => $amount,
                'reason' => $reason,
                'metadata' => $metadata,
                'initiated_by' => $initiatedBy,
                'initiated_at' => now(),
            ]);

            foreach ($steps as $step) {
                ApprovalStepInstance::create([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => tenant()->id,
                    'instance_id' => $instance->id,
                    'step_id' => $step->id,
                    'step_order' => $step->step_order,
                    'status' => 'pending',
                    'timeout_at' => $step->getTimeoutAt(),
                ]);
            }

            $instance->markInProgress();

            Log::info('Multi-step approval initiated', [
                'instance_id' => $instance->id,
                'workflow' => $workflowCode,
                'entity' => "{$entityType}:{$entityId}",
                'total_steps' => $steps->count(),
            ]);

            return $instance;
        });
    }

    /**
     * Approve a step in the workflow.
     */
    public function approveStep(
        string $instanceId,
        int $stepOrder,
        string $userId,
        ?string $notes = null
    ): ApprovalInstance {
        return DB::transaction(function () use ($instanceId, $stepOrder, $userId, $notes) {
            $instance = ApprovalInstance::where('tenant_id', tenant()->id)
                ->lockForUpdate()
                ->findOrFail($instanceId);

            if (! in_array($instance->status, ['pending', 'in_progress'])) {
                throw new \DomainException('APPROVAL_INSTANCE_NOT_ACTIVE');
            }

            $stepInstance = ApprovalStepInstance::where('tenant_id', tenant()->id)
                ->where('instance_id', $instanceId)
                ->where('step_order', $stepOrder)
                ->where('status', 'pending')
                ->firstOrFail();

            if ($stepInstance->isExpired()) {
                $stepInstance->skip('Step timed out');
                throw new \DomainException('APPROVAL_STEP_EXPIRED');
            }

            // Verify user has required role
            $step = $stepInstance->step;
            $user = User::findOrFail($userId);

            if (! $user->hasRole($step->required_role)) {
                throw new \DomainException('USER_LACKS_REQUIRED_ROLE');
            }

            // Segregation of duties check
            if ((string) $instance->initiated_by === (string) $userId) {
                throw new \DomainException('SELF_APPROVAL_NOT_PERMITTED');
            }

            $stepInstance->approve($userId, $notes);
            $instance->incrementCompletedSteps();

            // Check if workflow is complete
            if ($instance->isFullyApproved()) {
                $instance->markApproved();

                Log::info('Multi-step approval completed', [
                    'instance_id' => $instance->id,
                    'entity' => "{$instance->entity_type}:{$instance->entity_id}",
                ]);
            } else {
                // Advance to next step
                $nextStepOrder = $stepOrder + 1;
                $instance->advanceToStep($nextStepOrder);
            }

            return $instance->fresh();
        });
    }

    /**
     * Reject a step in the workflow.
     */
    public function rejectStep(
        string $instanceId,
        int $stepOrder,
        string $userId,
        string $reason
    ): ApprovalInstance {
        return DB::transaction(function () use ($instanceId, $stepOrder, $userId, $reason) {
            $instance = ApprovalInstance::where('tenant_id', tenant()->id)
                ->lockForUpdate()
                ->findOrFail($instanceId);

            if (! in_array($instance->status, ['pending', 'in_progress'])) {
                throw new \DomainException('APPROVAL_INSTANCE_NOT_ACTIVE');
            }

            $stepInstance = ApprovalStepInstance::where('tenant_id', tenant()->id)
                ->where('instance_id', $instanceId)
                ->where('step_order', $stepOrder)
                ->where('status', 'pending')
                ->firstOrFail();

            // Verify user has required role
            $step = $stepInstance->step;
            $user = User::findOrFail($userId);

            if (! $user->hasRole($step->required_role)) {
                throw new \DomainException('USER_LACKS_REQUIRED_ROLE');
            }

            $stepInstance->reject($userId, $reason);
            $instance->markRejected($reason);

            Log::info('Multi-step approval rejected', [
                'instance_id' => $instance->id,
                'step_order' => $stepOrder,
                'rejected_by' => $userId,
                'reason' => $reason,
            ]);

            return $instance->fresh();
        });
    }

    /**
     * Get pending approvals for a user.
     */
    public function getPendingForUser(string $userId): \Illuminate\Database\Eloquent\Collection
    {
        return ApprovalStepInstance::where('tenant_id', tenant()->id)
            ->where('assigned_to', $userId)
            ->where('status', 'pending')
            ->where('timeout_at', '>', now())
            ->with(['instance', 'step', 'instance.workflow'])
            ->orderBy('timeout_at', 'asc')
            ->get();
    }

    /**
     * Get active approval instances.
     */
    public function getActiveInstances(?string $entityType = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = ApprovalInstance::where('tenant_id', tenant()->id)
            ->active()
            ->with(['workflow', 'currentStepInstance.step']);

        if ($entityType) {
            $query->where('entity_type', $entityType);
        }

        return $query->orderBy('initiated_at', 'asc')->get();
    }

    /**
     * Expire timed-out steps and instances.
     */
    public function expireTimedOut(): array
    {
        $expiredSteps = ApprovalStepInstance::where('tenant_id', tenant()->id)
            ->where('status', 'pending')
            ->where('timeout_at', '<=', now())
            ->get();

        $expiredInstances = 0;

        foreach ($expiredSteps as $step) {
            $step->skip('Step timed out');

            $instance = $step->instance;
            if ($instance->status === 'in_progress') {
                $instance->markExpired();
                $expiredInstances++;
            }
        }

        return [
            'expired_steps' => $expiredSteps->count(),
            'expired_instances' => $expiredInstances,
        ];
    }
}
