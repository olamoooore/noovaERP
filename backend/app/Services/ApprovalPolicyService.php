<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Approval Policy Service
 *
 * Enforces approval requirements based on:
 * - action type
 * - amount threshold
 * - period sensitivity
 * - user role
 * - override reason
 */
class ApprovalPolicyService
{
    /**
     * Check if an action requires approval based on thresholds
     */
    public function requiresApproval(
        string $actionType,
        float $amount,
        User $actor,
        ?string $reason = null
    ): array {
        $threshold = $this->getThresholdForAction($actionType);

        if ($amount < $threshold) {
            return [
                'required' => false,
                'reason' => null,
                'threshold' => $threshold,
            ];
        }

        $hasOverridePermission = $this->userHasOverridePermission($actor, $actionType, $reason);

        if ($hasOverridePermission) {
            return [
                'required' => false,
                'reason' => 'User has override permission',
                'threshold' => $threshold,
            ];
        }

        return [
            'required' => true,
            'reason' => "Amount {$amount} exceeds threshold {$threshold} for {$actionType}. Approval required.",
            'threshold' => $threshold,
            'approver_roles' => $this->getApproverRoles($actionType),
        ];
    }

    /**
     * Get threshold for action type
     */
    private function getThresholdForAction(string $actionType): float
    {
        return match ($actionType) {
            'manual_journal' => $this->getManualJournalThreshold(),
            'reversal' => $this->getReversalThreshold(),
            'backdated_post' => 0,
            'lock_date_override' => 0,
            default => PHP_FLOAT_MAX,
        };
    }

    /**
     * Manual journal threshold - configurable
     */
    private function getManualJournalThreshold(): float
    {
        return (float) settings()->get('accounting.manual_journal_threshold', 10000);
    }

    /**
     * Reversal threshold - configurable
     */
    private function getReversalThreshold(): float
    {
        return (float) settings()->get('accounting.reversal_threshold', 5000);
    }

    /**
     * Check if user has override permission
     */
    private function userHasOverridePermission(User $user, string $actionType, ?string $reason): bool
    {
        if ($user->is_admin) {
            return true;
        }

        if ($reason && str_starts_with($reason, 'AUDIT_ADJUSTMENT')) {
            return $user->hasPermission('accounting.audit_adjustment');
        }

        if ($actionType === 'backdated_post') {
            return $user->hasPermission('accounting.backdate_override');
        }

        if ($actionType === 'lock_date_override') {
            return $user->hasPermission('accounting.lock_date_override');
        }

        if ($actionType === 'reversal') {
            return $user->hasPermission('accounting.reversal_approve');
        }

        return $user->hasPermission('accounting.approve_high_value');
    }

    /**
     * Get required approver roles for action
     */
    private function getApproverRoles(string $actionType): array
    {
        return match ($actionType) {
            'manual_journal' => ['accounting_manager', 'finance_director'],
            'reversal' => ['accounting_manager', 'finance_director'],
            'backdated_post' => ['finance_director'],
            'lock_date_override' => ['finance_director'],
            default => ['accounting_manager'],
        };
    }

    /**
     * Validate creator cannot self-approve
     */
    public function validateNotSelfApproval(int $actorUserId, ?int $approverUserId): array
    {
        if ($approverUserId === null) {
            return [
                'allowed' => true,
                'reason' => 'No approval required or auto-approved',
            ];
        }

        if ($actorUserId === $approverUserId) {
            Log::warning('Self-approval blocked', [
                'actor_user_id' => $actorUserId,
                'approver_user_id' => $approverUserId,
            ]);

            return [
                'allowed' => false,
                'reason' => 'Self-approval is not permitted. A different approver is required.',
            ];
        }

        return [
            'allowed' => true,
            'reason' => 'Approved',
        ];
    }
}