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
