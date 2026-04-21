<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\OverrideRequest;
use Modules\FinancialClose\Models\OverrideApproval;
use Modules\FinancialClose\Models\ExceptionRegister;
use Modules\FinancialClose\Models\AuditLog;

class OverrideGovernanceService
{
    /**
     * Create an override request
     * Overrides do not mutate source truth
     */
    public function createRequest(string $tenantId, string $requestedBy, string $entityType, string $entityId, string $overrideType, string $reason, ?string $reasonCode = null): ?OverrideRequest
    {
        return OverrideRequest::create([
            'tenant_id' => $tenantId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'override_type' => $overrideType,
            'status' => 'pending',
            'requested_by' => $requestedBy,
            'reason' => $reason,
            'reason_code' => $reasonCode,
        ]);
    }

    /**
     * Approve an override request
     */
    public function approveRequest(string $tenantId, string $requestId, string $approvedBy, ?string $notes = null): bool
    {
        $request = OverrideRequest::where('tenant_id', $tenantId)
            ->where('id', $requestId)
            ->first();

        if (!$request || $request->status !== 'pending') {
            return false;
        }

        $request->update(['status' => 'approved']);

        OverrideApproval::where('tenant_id', $tenantId)
            ->where('override_request_id', $requestId)
            ->where('status', 'pending')
            ->update([
                'status' => 'approved',
                'approved_by' => $approvedBy,
                'notes' => $notes,
                'decided_at' => now(),
            ]);

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $approvedBy,
            'action' => 'override_approved',
            'entity_type' => 'override_request',
            'entity_id' => $requestId,
        ]);

        return true;
    }

    /**
     * Reject an override request
     */
    public function rejectRequest(string $tenantId, string $requestId, string $rejectedBy, ?string $notes = null): bool
    {
        $request = OverrideRequest::where('tenant_id', $tenantId)
            ->where('id', $requestId)
            ->first();

        if (!$request || $request->status !== 'pending') {
            return false;
        }

        $request->update(['status' => 'rejected']);

        OverrideApproval::where('tenant_id', $tenantId)
            ->where('override_request_id', $requestId)
            ->where('status', 'pending')
            ->update([
                'status' => 'rejected',
                'approved_by' => $rejectedBy,
                'notes' => $notes,
                'decided_at' => now(),
            ]);

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $rejectedBy,
            'action' => 'override_rejected',
            'entity_type' => 'override_request',
            'entity_id' => $requestId,
        ]);

        return true;
    }

    /**
     * Register an exception
     */
    public function registerException(string $tenantId, string $requestId, string $entityId, string $exceptionType, ?string $description = null): ?ExceptionRegister
    {
        return ExceptionRegister::create([
            'tenant_id' => $tenantId,
            'override_request_id' => $requestId,
            'entity_id' => $entityId,
            'exception_type' => $exceptionType,
            'status' => 'open',
            'description' => $description,
        ]);
    }

    /**
     * Get all override requests for a tenant
     */
    public function listRequests(string $tenantId, array $filters = []): array
    {
        $query = OverrideRequest::where('tenant_id', $tenantId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('created_at', 'desc')->get()->toArray();
    }
}