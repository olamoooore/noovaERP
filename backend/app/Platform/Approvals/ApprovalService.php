<?php

namespace App\Platform\Approvals;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Platform\Approvals\Contracts\ApprovalServiceContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ApprovalService implements ApprovalServiceContract
{
    /**
     * Create an approval request.
     */
    public function createRequest(
        string $type,
        string $subjectType,
        int $subjectId,
        float $amount,
        string $reason,
        ?string $notes = null,
        ?int $userId = null
    ): object {
        return DB::transaction(function () use ($type, $subjectType, $subjectId, $amount, $reason, $notes, $userId) {
            // Check for existing pending request
            $existing = ApprovalRequest::where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('request_type', $type)
                ->where('status', 'pending')
                ->first();

            if ($existing) {
                return (object) [
                    'id' => $existing->id,
                    'status' => 'pending',
                    'duplicate' => true,
                ];
            }

            // Check policy for required approvers
            $policy = $this->findPolicy($type, $amount);
            $requiredApprovers = $policy ? $policy->required_approvers : null;

            $request = ApprovalRequest::create([
                'request_type' => $type,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'amount' => $amount,
                'reason' => $reason,
                'notes' => $notes,
                'requested_by' => $userId ?? auth()->id(),
                'requested_at' => now(),
                'status' => 'pending',
                'metadata' => [
                    'required_approvers' => $requiredApprovers,
                    'policy_id' => $policy?->id,
                ],
            ]);

            Log::info('Approval request created', [
                'request_id' => $request->id,
                'type' => $type,
                'amount' => $amount,
            ]);

            return (object) [
                'id' => $request->id,
                'status' => 'pending',
                'duplicate' => false,
            ];
        });
    }

    /**
     * Approve a request.
     */
    public function approve(int|string $requestId, int $userId, ?string $notes = null): object
    {
        return DB::transaction(function () use ($requestId, $userId, $notes) {
            $request = ApprovalRequest::lockForUpdate()->findOrFail($requestId);

            if (!$request->isPending()) {
                throw new \DomainException("Request is {$request->status}, cannot approve");
            }

            if ($request->isExpired()) {
                $request->update(['status' => 'expired']);
                throw new \DomainException("Request has expired");
            }

            // Self-approval check
            if ($request->requested_by == $userId) {
                throw new \DomainException("Self-approval is not permitted");
            }

            // Check delegation
            $actualApprover = $this->resolveDelegatedApprover($userId);

            $request->update([
                'status' => 'approved',
                'reviewed_by' => $actualApprover,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            Log::info('Approval request approved', [
                'request_id' => $request->id,
                'approved_by' => $actualApprover,
            ]);

            return (object) [
                'id' => $request->id,
                'status' => 'approved',
            ];
        });
    }

    /**
     * Reject a request.
     */
    public function reject(int|string $requestId, int $userId, string $reason): object
    {
        return DB::transaction(function () use ($requestId, $userId, $reason) {
            $request = ApprovalRequest::lockForUpdate()->findOrFail($requestId);

            if (!$request->isPending()) {
                throw new \DomainException("Request is {$request->status}, cannot reject");
            }

            $request->update([
                'status' => 'rejected',
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'review_notes' => $reason,
            ]);

            Log::info('Approval request rejected', [
                'request_id' => $request->id,
                'rejected_by' => $userId,
                'reason' => $reason,
            ]);

            return (object) [
                'id' => $request->id,
                'status' => 'rejected',
            ];
        });
    }

    /**
     * Escalate a request (24h auto-escalation).
     */
    public function escalate(int|string $requestId): object
    {
        return DB::transaction(function () use ($requestId) {
            $request = ApprovalRequest::lockForUpdate()->findOrFail($requestId);

            if (!$request->isPending()) {
                throw new \DomainException("Request is {$request->status}, cannot escalate");
            }

            // Find next approver role
            $policy = $this->findPolicy($request->request_type, $request->amount);
            $nextRole = $policy?->escalation_role ?? 'admin';

            // Record escalation
            ApprovalEscalation::create([
                'approval_request_id' => $request->id,
                'tenant_id' => tenant()?->id,
                'escalated_from' => $request->reviewed_by,
                'escalation_role' => $nextRole,
                'escalated_at' => now(),
                'reason' => 'Auto-escalated after timeout',
            ]);

            // Update escalation level
            $request->increment('escalation_level');

            Log::info('Approval request escalated', [
                'request_id' => $request->id,
                'level' => $request->escalation_level,
                'next_role' => $nextRole,
            ]);

            return (object) [
                'id' => $request->id,
                'status' => 'escalated',
                'escalation_role' => $nextRole,
            ];
        });
    }

    /**
     * Delegate approval authority from one user to another.
     */
    public function delegate(int $fromUserId, int $toUserId, ?string $expiresAt = null): void
    {
        // Remove existing active delegation
        ApprovalDelegation::where('from_user_id', $fromUserId)
            ->where('tenant_id', tenant()?->id)
            ->delete();

        ApprovalDelegation::create([
            'id' => Str::uuid(),
            'tenant_id' => tenant()?->id,
            'from_user_id' => $fromUserId,
            'to_user_id' => $toUserId,
            'expires_at' => $expiresAt ? now()->parse($expiresAt) : null,
        ]);
    }

    /**
     * Remove delegation for a user.
     */
    public function removeDelegation(int $fromUserId): void
    {
        ApprovalDelegation::where('from_user_id', $fromUserId)
            ->where('tenant_id', tenant()?->id)
            ->delete();
    }

    /**
     * Expire old pending requests.
     */
    public function expireOld(): int
    {
        return ApprovalRequest::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired', 'reviewed_at' => now()]);
    }

    /**
     * Get pending approval requests.
     */
    public function getPending(?string $type = null, ?int $userId = null): array
    {
        $query = ApprovalRequest::where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('requested_at', 'asc');

        if ($type) {
            $query->where('request_type', $type);
        }

        return $query->get()->toArray();
    }

    /**
     * Check approval policy for a request type and amount.
     */
    public function checkPolicy(string $type, float $amount, int $userId): array
    {
        $policy = $this->findPolicy($type, $amount);

        if (!$policy) {
            return [
                'required' => false,
                'reason' => 'No policy defined',
            ];
        }

        if ($amount < $policy->threshold_amount) {
            return [
                'required' => false,
                'reason' => "Amount {$amount} below threshold {$policy->threshold_amount}",
                'threshold' => $policy->threshold_amount,
            ];
        }

        return [
            'required' => true,
            'reason' => "Amount {$amount} exceeds threshold {$policy->threshold_amount}",
            'threshold' => $policy->threshold_amount,
            'required_approvers' => $policy->required_approvers,
        ];
    }

    // ─── Private ───────────────────────────────────────────

    private function findPolicy(string $type, float $amount): ?ApprovalPolicy
    {
        return ApprovalPolicy::active()
            ->forType($type)
            ->where('threshold_amount', '<=', $amount)
            ->orderBy('threshold_amount', 'desc')
            ->first();
    }

    private function resolveDelegatedApprover(int $userId): int
    {
        $delegation = ApprovalDelegation::where('from_user_id', $userId)
            ->where('tenant_id', tenant()?->id)
            ->active()
            ->first();

        if ($delegation) {
            return $delegation->to_user_id;
        }

        return $userId;
    }
}
