<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Services\ApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ApprovalController extends Controller
{
    public function __construct(
        private ApprovalService $approvalService
    ) {}

    /**
     * Create an approval request (POST /approvals)
     * Accepts backend field names: request_type, subject_type, subject_id, amount, reason
     * Also accepts frontend aliases: type → request_type, source_type → subject_type, source_id → subject_id
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'request_type' => 'required_without:type|string|in:void,refund,return,discount,price_override',
            'type'         => 'required_without:request_type|string|in:void,refund,return,discount,price_override',
            'subject_type' => 'required_without:source_type|string',
            'source_type'  => 'required_without:subject_type|string',
            'subject_id'   => 'required_without:source_id|integer',
            'source_id'    => 'required_without:subject_id|integer',
            'amount'       => 'required|numeric|min:0',
            'reason'       => 'required|string|min:10|max:500',
            'notes'        => 'nullable|string|max:500',
            'metadata'     => 'nullable|array',
        ]);

        // Normalise frontend aliases to backend field names
        $requestType = $validated['request_type'] ?? $validated['type'];
        $subjectType = $validated['subject_type'] ?? $validated['source_type'];
        $subjectId   = (int) ($validated['subject_id'] ?? $validated['source_id']);

        try {
            $approvalRequest = $this->approvalService->createApprovalRequest(
                requestType: $requestType,
                subjectType: $subjectType,
                subjectId:   $subjectId,
                amount:      (float) $validated['amount'],
                reason:      $validated['reason'],
                notes:       $validated['notes'] ?? null,
                metadata:    $validated['metadata'] ?? null,
            );

            return response()->json([
                'message' => 'Approval request created',
                'data'    => $approvalRequest->load(['requestedBy']),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Approval request creation failed', [
                'user_id' => auth()->id(),
                'error'   => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * List pending approval requests
     */
    public function index(Request $request)
    {
        $requestType = $request->input('type');
        $requests = $this->approvalService->getPendingRequests($requestType);
        
        return response()->json([
            'data' => $requests,
            'count' => $requests->count(),
        ]);
    }

    /**
     * Get a specific approval request
     */
    public function show($id)
    {
        $request = ApprovalRequest::with(['requestedBy', 'reviewedBy', 'subject'])
            ->findOrFail($id);
        
        return response()->json(['data' => $request]);
    }

    /**
     * Approve a request
     */
    public function approve(Request $request, $id)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $approvalRequest = $this->approvalService->approveRequest(
                $id,
                auth()->id(),
                $validated['notes'] ?? null
            );

            return response()->json([
                'message' => 'Request approved successfully',
                'data' => $approvalRequest->load(['requestedBy', 'reviewedBy']),
            ]);
        } catch (\Exception $e) {
            Log::error('Approval failed', [
                'request_id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 403);
        }
    }

    /**
     * Reject a request
     */
    public function reject(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:500',
        ]);

        try {
            $approvalRequest = $this->approvalService->rejectRequest(
                $id,
                auth()->id(),
                $validated['reason']
            );

            return response()->json([
                'message' => 'Request rejected successfully',
                'data' => $approvalRequest->load(['requestedBy', 'reviewedBy']),
            ]);
        } catch (\Exception $e) {
            Log::error('Rejection failed', [
                'request_id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 403);
        }
    }

    /**
     * Get approval requests for current user
     */
    public function myRequests(Request $request)
    {
        $status = $request->input('status', 'pending');
        
        $requests = ApprovalRequest::where('requested_by', auth()->id())
            ->where('status', $status)
            ->with(['reviewedBy', 'subject'])
            ->orderBy('requested_at', 'desc')
            ->paginate(20);
        
        return response()->json($requests);
    }

    /**
     * Expire old pending requests (cron job endpoint)
     */
    public function expireOld()
    {
        $count = $this->approvalService->expireOldRequests();
        
        return response()->json([
            'message' => "Expired {$count} old approval requests",
            'count' => $count,
        ]);
    }
}
