<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Services\ApprovalService;
use App\Services\ApprovalVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ApprovalController extends Controller
{
    public function __construct(
        private ApprovalService $approvalService,
        private ApprovalVisibilityService $visibility
    ) {}

    /**
     * Create an approval request (POST /approvals)
     * Accepts backend field names: request_type, subject_type, subject_id, amount, reason
     * Also accepts frontend aliases: type → request_type, source_type → subject_type, source_id → subject_id
     *
     * subject_type/source_type are limited to the config/approvals.php
     * whitelist (issue #651): an arbitrary value used to be accepted and then
     * fed to the polymorphic subject relation by list/show.
     */
    public function store(Request $request)
    {
        $allowedSubjectTypes = array_keys((array) config('approvals.subject_types', []));

        $validated = $request->validate([
            'request_type' => 'required_without:type|string|in:void,refund,return,discount,price_override',
            'type' => 'required_without:request_type|string|in:void,refund,return,discount,price_override',
            'subject_type' => ['required_without:source_type', 'string', Rule::in($allowedSubjectTypes)],
            'source_type' => ['required_without:subject_type', 'string', Rule::in($allowedSubjectTypes)],
            'subject_id' => 'required_without:source_id|integer',
            'source_id' => 'required_without:subject_id|integer',
            'amount' => 'required|numeric|min:0',
            'reason' => 'required|string|min:10|max:500',
            'notes' => 'nullable|string|max:500',
            'metadata' => 'nullable|array',
        ]);

        // Normalise frontend aliases to backend field names
        $requestType = $validated['request_type'] ?? $validated['type'];
        $subjectType = $validated['subject_type'] ?? $validated['source_type'];
        $subjectId = (int) ($validated['subject_id'] ?? $validated['source_id']);

        try {
            $approvalRequest = $this->approvalService->createApprovalRequest(
                requestType: $requestType,
                subjectType: $subjectType,
                subjectId: $subjectId,
                amount: (float) $validated['amount'],
                reason: $validated['reason'],
                notes: $validated['notes'] ?? null,
                metadata: $validated['metadata'] ?? null,
            );

            return response()->json([
                'message' => 'Approval request created',
                'data' => $approvalRequest->load(['requestedBy']),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Approval request creation failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * List pending approval requests the caller may see (issue #651).
     *
     * Scoped to: requests they can decide, requests they raised themselves,
     * and requests whose subject document they may view — entity-scoped to
     * their accessible legal entities.
     */
    public function index(Request $request)
    {
        $requestType = $request->input('type');
        $requests = $this->approvalService->getPendingRequests($requestType);

        ApprovalRequest::loadSubjects($requests);

        $visible = $this->visibility->visible($requests, $request->user());

        return response()->json([
            'data' => $visible,
            'count' => $visible->count(),
        ]);
    }

    /**
     * Get a specific approval request — same visibility rule as the list
     * (issue #651): another user's / another entity's request is 403.
     */
    public function show(Request $request, $id)
    {
        $approvalRequest = ApprovalRequest::with(['requestedBy', 'reviewedBy'])
            ->findOrFail($id);

        ApprovalRequest::loadSubjects(collect([$approvalRequest]));

        abort_unless(
            $this->visibility->canView($approvalRequest, $request->user()),
            403,
            'You do not have permission to view this approval request.'
        );

        return response()->json(['data' => $approvalRequest]);
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
            ->with(['reviewedBy'])
            ->orderBy('requested_at', 'desc')
            ->paginate(20);

        // Safe subject attachment (issue #651): the morph `subject` eager load
        // 500s on the POS alias 'PosOrder', which every POS request carries.
        ApprovalRequest::loadSubjects($requests->items());

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
