<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LockDate;
use App\Models\LockDateOverride;
use App\Services\ApprovalPolicyService;
use App\Services\LockDateValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * Lock Dates Controller
 * Manages accounting period lock dates
 */
class LockDatesController extends Controller
{
    protected $lockDateService;
    protected $approvalPolicy;

    public function __construct(LockDateValidationService $lockDateService, ApprovalPolicyService $approvalPolicy)
    {
        $this->lockDateService = $lockDateService;
        $this->approvalPolicy = $approvalPolicy;
    }

    /**
     * Get all lock dates
     */
    public function index(Request $request)
    {
        $query = LockDate::with('setBy');

        // Filter by type
        if ($request->has('lock_type')) {
            $query->where('lock_type', $request->lock_type);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        $lockDates = $query->orderBy('lock_date', 'desc')->get();

        return response()->json($lockDates);
    }

    /**
     * Get current active lock dates
     */
    public function current()
    {
        $lockDates = LockDate::where('is_active', true)
            ->with('setBy')
            ->orderBy('lock_date', 'desc')
            ->get()
            ->groupBy('lock_type')
            ->map(function ($group) {
                return $group->first(); // Get the most recent lock date for each type
            });

        return response()->json($lockDates);
    }

    /**
     * Get a specific lock date
     */
    public function show($id)
    {
        $lockDate = LockDate::with('setBy')->findOrFail($id);
        return response()->json($lockDate);
    }

    /**
     * Create a new lock date
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'lock_type' => 'required|in:all,sales,purchases,inventory,payroll,journal_entries,assets',
            'lock_date' => 'required|date',
            'reason' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Deactivate previous lock dates of the same type
        LockDate::where('lock_type', $request->lock_type)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $lockDate = LockDate::create([
            'lock_type' => $request->lock_type,
            'lock_date' => $request->lock_date,
            'set_by' => TenantUserHelper::requireTenantUserId(),
            'set_at' => now(),
            'reason' => $request->reason,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Lock date set successfully',
            'lock_date' => $lockDate->load('setBy')
        ], 201);
    }

    /**
     * Update a lock date
     */
    public function update(Request $request, $id)
    {
        $lockDate = LockDate::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'lock_date' => 'sometimes|date',
            'reason' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $lockDate->update($request->only(['lock_date', 'reason', 'is_active']));

        return response()->json([
            'message' => 'Lock date updated successfully',
            'lock_date' => $lockDate->fresh('setBy')
        ]);
    }

    /**
     * Delete (deactivate) a lock date
     */
    public function destroy($id)
    {
        $lockDate = LockDate::findOrFail($id);
        $lockDate->update(['is_active' => false]);

        return response()->json([
            'message' => 'Lock date deactivated successfully'
        ]);
    }

    /**
     * Validate a transaction date against lock dates
     */
    public function validateDate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'transaction_type' => 'required|string',
            'transaction_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $result = $this->lockDateService->validateTransactionDate(
            $request->transaction_type,
            $request->transaction_date
        );

        return response()->json($result);
    }

    /**
     * Request override for a locked period
     */
    public function requestOverride(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'transaction_type' => 'required|string',
            'transaction_id' => 'required|integer',
            'transaction_date' => 'required|date',
            'override_reason' => 'required|string|min:10',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if user has override permission
        if (!$this->lockDateService->canOverrideLockDate(TenantUserHelper::requireTenantUserId())) {
            return response()->json([
                'message' => 'You do not have permission to override lock dates',
            ], 403);
        }

        $override = LockDateOverride::logOverride(
            TenantUserHelper::requireTenantUserId(),
            $request->transaction_type,
            $request->transaction_id,
            $request->transaction_date,
            $request->override_reason,
            null // Requires separate approval
        );

        return response()->json([
            'message' => 'Override logged successfully',
            'override' => $override->load(['user', 'approvedBy'])
        ], 201);
    }

    /**
     * Get override history
     */
    public function overrides(Request $request)
    {
        $query = LockDateOverride::with(['user', 'approvedBy']);

        // Filter by user
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by transaction type
        if ($request->has('transaction_type')) {
            $query->where('transaction_type', $request->transaction_type);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('transaction_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('transaction_date', '<=', $request->end_date);
        }

        $overrides = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json($overrides);
    }

    /**
     * Get lock date statistics
     */
    public function statistics()
    {
        $stats = [
            'active_locks' => LockDate::where('is_active', true)->count(),
            'total_overrides' => LockDateOverride::count(),
            'recent_overrides' => LockDateOverride::where('created_at', '>=', now()->subDays(30))->count(),
            'locks_by_type' => LockDate::where('is_active', true)
                ->selectRaw('lock_type, COUNT(*) as count')
                ->groupBy('lock_type')
                ->get()
                ->pluck('count', 'lock_type'),
        ];

        return response()->json($stats);
    }

    /**
     * Approve a lock date override
     * Validates that the approver is not the same as the requestor (no self-approval)
     */
    public function approveOverride(Request $request, $overrideId)
    {
        $validator = Validator::make($request->all(), [
            'approved_by' => 'required|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $override = LockDateOverride::findOrFail($overrideId);

        if ($override->approved_by !== null) {
            return response()->json([
                'message' => 'This override has already been approved',
            ], 422);
        }

        // Self-approval validation
        $selfApprovalCheck = $this->approvalPolicy->validateNotSelfApproval(
            $override->user_id,
            $request->approved_by
        );

        if (!$selfApprovalCheck['allowed']) {
            Log::warning('Lock date override self-approval blocked', [
                'override_id' => $overrideId,
                'requestor_user_id' => $override->user_id,
                'approver_user_id' => $request->approved_by,
            ]);

            return response()->json([
                'message' => $selfApprovalCheck['reason'],
            ], 422);
        }

        // Validate approver has permission
        if (!$this->lockDateService->canOverrideLockDate($request->approved_by)) {
            return response()->json([
                'message' => 'The specified approver does not have permission to approve lock date overrides',
            ], 422);
        }

        $override->update([
            'approved_by' => $request->approved_by,
        ]);

        return response()->json([
            'message' => 'Override approved successfully',
            'override' => $override->fresh(['user', 'approvedBy']),
        ]);
    }
}
