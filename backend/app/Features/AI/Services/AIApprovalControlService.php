<?php

namespace App\Features\AI\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AI Approval Control Service
 *
 * Manages approval workflows for AI actions:
 * - Records approval requests
 * - Tracks approval status
 * - Enforces approval policies
 */
class AIApprovalControlService
{
    /**
     * Request approval for an AI action
     *
     * @param  string  $action  The AI action requiring approval
     * @param  User  $requestor  The user requesting the action
     * @param  array  $context  Action context (module, entity, data, etc.)
     * @param  string|null  $reason  Explanation for the action
     * @return array{approval_id: int, status: string, message: string}
     */
    public function requestApproval(
        string $action,
        User $requestor,
        array $context = [],
        ?string $reason = null
    ): array {
        $approvalId = DB::table('ai_approval_requests')->insertGetId([
            'action' => $action,
            'requestor_id' => $requestor->id,
            'tenant_id' => tenant('id'),
            'module' => $context['module'] ?? null,
            'entity_type' => $context['entity_type'] ?? null,
            'entity_id' => $context['entity_id'] ?? null,
            'reason' => $reason,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('AI approval requested', [
            'approval_id' => $approvalId,
            'action' => $action,
            'requestor_id' => $requestor->id,
        ]);

        return [
            'approval_id' => $approvalId,
            'status' => 'pending',
            'message' => 'Approval request submitted. Waiting for approver.',
        ];
    }

    /**
     * Approve an AI action
     *
     * @param  int  $approvalId  The approval request ID
     * @param  User  $approver  The user approving the action
     * @param  string|null  $comment  Approver's comment
     * @return array{success: bool, message: string}
     */
    public function approve(int $approvalId, User $approver, ?string $comment = null): array
    {
        $approval = DB::table('ai_approval_requests')
            ->where('id', $approvalId)
            ->where('status', 'pending')
            ->first();

        if (! $approval) {
            return [
                'success' => false,
                'message' => 'Approval request not found or already processed.',
            ];
        }

        // Prevent self-approval
        if ($approval->requestor_id === $approver->id) {
            Log::warning('AI self-approval attempted', [
                'approval_id' => $approvalId,
                'user_id' => $approver->id,
            ]);

            return [
                'success' => false,
                'message' => 'Self-approval is not permitted. A different approver is required.',
            ];
        }

        // Check approver has permission
        if (! $this->userCanApprove($approver)) {
            return [
                'success' => false,
                'message' => 'User does not have permission to approve AI actions.',
            ];
        }

        DB::table('ai_approval_requests')
            ->where('id', $approvalId)
            ->update([
                'status' => 'approved',
                'approver_id' => $approver->id,
                'approval_comment' => $comment,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);

        Log::info('AI action approved', [
            'approval_id' => $approvalId,
            'action' => $approval->action,
            'approver_id' => $approver->id,
        ]);

        return [
            'success' => true,
            'message' => 'Action approved successfully.',
        ];
    }

    /**
     * Reject an AI action
     *
     * @param  int  $approvalId  The approval request ID
     * @param  User  $approver  The user rejecting the action
     * @param  string  $reason  Reason for rejection
     * @return array{success: bool, message: string}
     */
    public function reject(int $approvalId, User $approver, string $reason): array
    {
        $approval = DB::table('ai_approval_requests')
            ->where('id', $approvalId)
            ->where('status', 'pending')
            ->first();

        if (! $approval) {
            return [
                'success' => false,
                'message' => 'Approval request not found or already processed.',
            ];
        }

        DB::table('ai_approval_requests')
            ->where('id', $approvalId)
            ->update([
                'status' => 'rejected',
                'approver_id' => $approver->id,
                'rejection_reason' => $reason,
                'rejected_at' => now(),
                'updated_at' => now(),
            ]);

        Log::info('AI action rejected', [
            'approval_id' => $approvalId,
            'action' => $approval->action,
            'approver_id' => $approver->id,
            'reason' => $reason,
        ]);

        return [
            'success' => true,
            'message' => 'Action rejected.',
        ];
    }

    /**
     * Check if an approval exists and is approved
     *
     * @param  int  $approvalId  The approval request ID
     * @return array{exists: bool, approved: bool, status: string|null}
     */
    public function checkApprovalStatus(int $approvalId): array
    {
        $approval = DB::table('ai_approval_requests')
            ->where('id', $approvalId)
            ->first();

        if (! $approval) {
            return [
                'exists' => false,
                'approved' => false,
                'status' => null,
            ];
        }

        return [
            'exists' => true,
            'approved' => $approval->status === 'approved',
            'status' => $approval->status,
        ];
    }

    /**
     * Get pending approvals for a tenant
     */
    public function getPendingApprovals(?string $tenantId = null): array
    {
        $query = DB::table('ai_approval_requests')
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc');

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->get()->toArray();
    }

    /**
     * Check if user can approve AI actions
     */
    private function userCanApprove(User $user): bool
    {
        // Super admin can always approve
        if ($user->is_super_admin) {
            return true;
        }

        // Check explicit permission
        if (method_exists($user, 'hasPermissionTo')) {
            try {
                return $user->hasPermissionTo('ai.approve');
            } catch (\Exception $e) {
                // Permission doesn't exist
            }
        }

        // Check role
        return $user->hasRole(['admin', 'ai_approver', 'finance_director']);
    }
}
