<?php

namespace App\Services;

use App\Accounting\Events\ApprovalDecisionEvent;
use App\Enums\UserRole;
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
        // Owner/Admin implicitly bypass fine-grained checks, per
        // UserRole::superRoles(). Segregation of duties is enforced separately
        // in approveRequest() — the requester still cannot approve their own
        // request, whatever their role.
        if ($this->hasSuperRole($user)) {
            return true;
        }

        // Sales approvals are reviewer-driven (Sales Settings → Approval
        // Workflow), not entitlement-driven. Issue #645: this branch covers
        // quotation, sales-order and invoice-threshold requests too — before
        // it, only invoice/DO were special-cased and every other sales type
        // fell into ENTITLEMENT_MAP, logged "Unknown request type", and
        // returned false, so nobody could approve them.
        $gate = app(SalesApprovalGate::class);

        if ($gate->isSalesRequestType($requestType)) {
            return $gate->resolveReviewers($requestType)->contains('id', $user->id);
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
     * Whether the user holds a tenant role that implicitly bypasses
     * fine-grained permission checks (Owner/Admin).
     *
     * The role lives on public.user_company_access and is resolved through the
     * platform TenantRoleService — the same bridge the RequirePermission
     * middleware uses — rather than by hand-reading role strings or relying on
     * the Spatie role table alone.
     */
    private function hasSuperRole(User $user): bool
    {
        try {
            if (! function_exists('tenant') || ! tenant()) {
                return false;
            }

            $tenantId = (string) tenant('id');

            if ($tenantId === '') {
                return false;
            }

            $centralUserId = \App\Http\Middleware\RequirePermission::resolveCentralUserId($user);
            $role = app(TenantRoleService::class)->getRole($centralUserId, $tenantId);

            return $role !== null && in_array($role->value, UserRole::superRoles(), true);
        } catch (\Throwable $e) {
            Log::warning('ApprovalService: failed to resolve super-role bypass', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Pending sales approval requests that are already past their expiry —
     * the requests that sat in the inbox beyond their window and, before
     * issue #645, nobody could action. Diagnostic only: expireOldRequests()
     * is what mutates them.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ApprovalRequest>
     */
    public function pendingSalesRequestsPastExpiry(): \Illuminate\Database\Eloquent\Collection
    {
        return ApprovalRequest::query()
            ->whereIn('request_type', SalesApprovalGate::SALES_REQUEST_TYPES)
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->with(['requestedBy', 'subject'])
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Get pending approval requests.
     *
     * Does NOT eager-load `subject`: the morph loader does `new <subject_type>`,
     * which 500s on the POS alias 'PosOrder' and would instantiate arbitrary
     * classes for legacy rows. Callers attach subjects with
     * ApprovalRequest::loadSubjects() (issue #651) after applying visibility.
     */
    public function getPendingRequests(?string $requestType = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = ApprovalRequest::where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->with(['requestedBy'])
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
