<?php

namespace App\Services;

use App\Accounting\Events\ApprovalDecisionEvent;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class ApprovalService
{
    /**
     * Required entitlements for different approval types
     */
    private const ENTITLEMENT_MAP = [
        'void' => 'pos.void.approve',
        'refund' => 'pos.refund.approve',
        'return' => 'pos.return.approve',
        'discount' => 'pos.discount.approve',
        'price_override' => 'pos.price_override.approve',
    ];

    /**
     * Amount thresholds requiring supervisor approval
     */
    private const SUPERVISOR_THRESHOLD = 500.00;

    /**
     * Create an approval request
     */
    public function createApprovalRequest(
        string $requestType,
        string $subjectType,
        int $subjectId,
        float $amount,
        string $reason,
        ?string $notes = null,
        ?array $metadata = null
    ): ApprovalRequest {
        return DB::transaction(function () use (
            $requestType,
            $subjectType,
            $subjectId,
            $amount,
            $reason,
            $notes,
            $metadata
        ) {
            // Check for existing pending request
            $existing = ApprovalRequest::where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('request_type', $requestType)
                ->where('status', 'pending')
                ->first();

            if ($existing) {
                Log::info('Existing approval request found', [
                    'request_id' => $existing->id,
                    'request_number' => $existing->request_number,
                ]);

                return $existing;
            }

            $request = ApprovalRequest::create([
                'request_type' => $requestType,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'amount' => $amount,
                'reason' => $reason,
                'notes' => $notes,
                'metadata' => $metadata,
                'requested_by' => TenantUserHelper::resolveTenantUserId(),
                'requested_at' => now(),
                'status' => 'pending',
            ]);

            Log::info('Approval request created', [
                'request_id' => $request->id,
                'request_number' => $request->request_number,
                'request_type' => $requestType,
                'amount' => $amount,
            ]);

            return $request;
        });
    }

    /**
     * Approve a request (with entitlement check)
     */
    public function approveRequest(int $requestId, int $userId, ?string $notes = null): ApprovalRequest
    {
        return DB::transaction(function () use ($requestId, $userId, $notes) {
            $request = ApprovalRequest::lockForUpdate()->findOrFail($requestId);

            if (! $request->isPending()) {
                throw new \Exception("Request is {$request->status}, cannot approve");
            }

            if ($request->isExpired()) {
                $request->update(['status' => 'expired']);
                throw new \Exception('Request has expired');
            }

            // Segregation of duties — requester cannot approve own request
            if ($request->requested_by === $userId) {
                throw new \Exception('Self-approval is not permitted');
            }

            $user = User::findOrFail($userId);

            // Check entitlement
            if (! $this->userCanApprove($user, $request->request_type, $request->amount)) {
                throw new \Exception("User does not have permission to approve {$request->request_type} requests");
            }

            $request->update([
                'status' => 'approved',
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            Log::info('Approval request approved', [
                'request_id' => $request->id,
                'request_number' => $request->request_number,
                'approved_by' => $userId,
            ]);

            ApprovalDecisionEvent::dispatch($request, ApprovalDecisionEvent::ACTION_APPROVED, $user);

            return $request;
        });
    }

    /**
     * Reject a request (with entitlement check)
     */
    public function rejectRequest(int $requestId, int $userId, string $reason): ApprovalRequest
    {
        return DB::transaction(function () use ($requestId, $userId, $reason) {
            $request = ApprovalRequest::lockForUpdate()->findOrFail($requestId);

            if (! $request->isPending()) {
                throw new \Exception("Request is {$request->status}, cannot reject");
            }

            $user = User::findOrFail($userId);

            // Check entitlement
            if (! $this->userCanApprove($user, $request->request_type, $request->amount)) {
                throw new \Exception("User does not have permission to reject {$request->request_type} requests");
            }

            $request->update([
                'status' => 'rejected',
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'review_notes' => $reason,
            ]);

            Log::info('Approval request rejected', [
                'request_id' => $request->id,
                'request_number' => $request->request_number,
                'rejected_by' => $userId,
                'reason' => $reason,
            ]);

            ApprovalDecisionEvent::dispatch($request, ApprovalDecisionEvent::ACTION_REJECTED, $user, $reason);

            return $request;
        });
    }

    /**
     * Check if user can approve a request type
     */
    public function userCanApprove(User $user, string $requestType, float $amount): bool
    {
        // Invoice AND delivery-order approval are reviewer-driven (Sales
        // Settings → Approval Workflow), not entitlement-driven. A configured
        // reviewer may always decide — that list is the operator's intent and
        // must not be second-guessed by the entitlement/role heuristic below.
        // This must run BEFORE the entitlement map lookup, which has no key for
        // either request type.
        if ($requestType === \App\Services\InvoiceApprovalService::REQUEST_TYPE) {
            return app(\App\Services\InvoiceApprovalService::class)
                ->resolveReviewers(null)
                ->contains('id', $user->id);
        }

        if ($requestType === \App\Services\SalesApprovalGate::REQUEST_TYPE_DELIVERY_ORDER) {
            return app(\Modules\Sales\Services\DeliveryOrderService::class)
                ->resolveReviewers(null)
                ->contains('id', $user->id);
        }

        // Get required entitlement
        $requiredEntitlement = self::ENTITLEMENT_MAP[$requestType] ?? null;

        if (! $requiredEntitlement) {
            Log::warning('Unknown request type for approval', ['request_type' => $requestType]);

            return false;
        }

        // Check if user has manager role (basic approval)
        $hasManagerRole = $user->hasRole('manager') || $user->hasRole('supervisor');

        if (! $hasManagerRole) {
            return false;
        }

        // For high-value transactions, require supervisor role
        if ($amount > self::SUPERVISOR_THRESHOLD) {
            return $user->hasRole('supervisor');
        }

        return true;
    }

    /**
     * Get pending approval requests
     */
    public function getPendingRequests(?string $requestType = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = ApprovalRequest::where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->with(['requestedBy', 'subject'])
            ->orderBy('requested_at', 'asc');

        if ($requestType) {
            $query->where('request_type', $requestType);
        }

        return $query->get();
    }

    /**
     * Get approval request by subject
     */
    public function getRequestBySubject(string $subjectType, int $subjectId, string $requestType): ?ApprovalRequest
    {
        return ApprovalRequest::where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('request_type', $requestType)
            ->where('status', 'pending')
            ->first();
    }

    /**
     * Expire old pending requests
     */
    public function expireOldRequests(): int
    {
        $expiredRequests = ApprovalRequest::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expiredRequests as $request) {
            $request->update([
                'status' => 'expired',
                'reviewed_at' => now(),
            ]);

            ApprovalDecisionEvent::dispatch($request, ApprovalDecisionEvent::ACTION_EXPIRED);
        }

        if ($expiredRequests->isNotEmpty()) {
            Log::info('Expired old approval requests', ['count' => $expiredRequests->count()]);
        }

        return $expiredRequests->count();
    }
}
