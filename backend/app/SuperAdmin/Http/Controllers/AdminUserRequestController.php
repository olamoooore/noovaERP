<?php

namespace App\SuperAdmin\Http\Controllers;

use App\Models\Tenant;
use App\Models\UserCreationRequest;
use App\Services\TenantMemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * SuperAdmin console: tenant user-creation requests.
 *
 * Approving runs the shared TenantMemberService (the same path the
 * platform-only tenant invite endpoint uses), then records the review.
 */
class AdminUserRequestController extends Controller
{
    public function __construct(private readonly TenantMemberService $members) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|string|in:pending,approved,declined,all',
        ]);

        $status = $validated['status'] ?? 'pending';

        $query = UserCreationRequest::query()->orderByDesc('id');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $requests = $query->limit(100)->get();

        $tenantNames = DB::table('tenants')
            ->whereIn('id', $requests->pluck('tenant_id')->unique()->filter()->values())
            ->pluck('name', 'id');

        $requesterNames = DB::table('users')
            ->whereIn('id', $requests->pluck('requested_by')->filter()->unique()->values())
            ->pluck('name', 'id');

        return response()->json([
            'success' => true,
            'requests' => $requests->map(fn (UserCreationRequest $row) => array_merge($row->toArray(), [
                'tenant_name' => $tenantNames[$row->tenant_id] ?? null,
                'requested_by_name' => $requesterNames[$row->requested_by] ?? null,
            ])),
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $requestRow = UserCreationRequest::findOrFail($id);

        if ($requestRow->status !== 'pending') {
            return response()->json(['message' => "Request already {$requestRow->status}."], 422);
        }

        $tenant = Tenant::find($requestRow->tenant_id);

        if (! $tenant) {
            return response()->json(['message' => 'Tenant no longer exists — decline this request instead.'], 422);
        }

        // Tenant context so the invite email carries the workspace name.
        tenancy()->initialize($tenant);

        try {
            $result = $this->members->createOrAttachUser(
                (string) $tenant->id,
                (string) $requestRow->email,
                (string) $requestRow->role,
                $requestRow->name,
            );
        } finally {
            tenancy()->end();
        }

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'message' => $result['message'] ?? 'Unable to create the user.',
            ], 422);
        }

        $requestRow->update([
            'status' => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $result['message'],
        ]);

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'request' => $requestRow->fresh(),
        ]);
    }

    public function decline(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'review_note' => 'nullable|string|max:1000',
        ]);

        $requestRow = UserCreationRequest::findOrFail($id);

        if ($requestRow->status !== 'pending') {
            return response()->json(['message' => "Request already {$requestRow->status}."], 422);
        }

        $requestRow->update([
            'status' => 'declined',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $validated['review_note'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Request declined.',
            'request' => $requestRow->fresh(),
        ]);
    }
}
