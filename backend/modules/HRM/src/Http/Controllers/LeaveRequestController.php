<?php

namespace Modules\HRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\HRM\Http\Requests\StoreLeaveRequestRequest;
use Modules\HRM\Http\Resources\LeaveRequestResource;
use Modules\HRM\Models\LeaveRequest;

class LeaveRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = LeaveRequest::with('employee')->latest();

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $records = $query->paginate(30);

        return LeaveRequestResource::collection($records)->response();
    }

    public function store(StoreLeaveRequestRequest $request): JsonResponse
    {
        $leave = LeaveRequest::create($request->validated());
        $leave->load('employee');

        return (new LeaveRequestResource($leave))
            ->response()
            ->setStatusCode(201);
    }

    public function show(LeaveRequest $leaveRequest): JsonResponse
    {
        $leaveRequest->load('employee');

        return (new LeaveRequestResource($leaveRequest))->response();
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        if ($leaveRequest->status !== 'pending') {
            return response()->json([
                'message' => "Cannot approve a leave request that is already '{$leaveRequest->status}'.",
            ], 422);
        }

        $leaveRequest->update([
            'status' => 'approved',
            'feedback' => $request->input('feedback'),
        ]);

        return (new LeaveRequestResource($leaveRequest->fresh('employee')))->response();
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        if ($leaveRequest->status !== 'pending') {
            return response()->json([
                'message' => "Cannot reject a leave request that is already '{$leaveRequest->status}'.",
            ], 422);
        }

        $leaveRequest->update([
            'status' => 'rejected',
            'feedback' => $request->input('feedback'),
        ]);

        return (new LeaveRequestResource($leaveRequest->fresh('employee')))->response();
    }

    public function destroy(LeaveRequest $leaveRequest): JsonResponse
    {
        $leaveRequest->delete();

        return response()->json(null, 204);
    }
}
