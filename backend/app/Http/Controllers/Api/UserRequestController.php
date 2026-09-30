<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequirePermission;
use App\Models\UserCreationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tenant-side "request a new user" flow.
 *
 * Tenant user creation is restricted to the NoovaERP platform team (the
 * invite endpoint accepts platform superadmins only). Owners/admins file a
 * request here instead; the SuperAdmin console approves it — creating the
 * account through the shared TenantMemberService — or declines it.
 */
class UserRequestController extends Controller
{
    /**
     * GET /api/v1/tenant-users/requests — this tenant's requests + statuses.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = RequirePermission::resolveTenantId($request);

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant context required.'], 403);
        }

        return response()->json([
            'success' => true,
            'requests' => UserCreationRequest::query()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('id')
                ->limit(25)
                ->get(),
        ]);
    }

    /**
     * POST /api/v1/tenant-users/requests — file a new-user request.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = RequirePermission::resolveTenantId($request);

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant context required.'], 403);
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in(UserRole::values())],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $email = strtolower(trim($validated['email']));

        $duplicate = UserCreationRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('email', $email)
            ->where('status', 'pending')
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'A pending request for this email already exists.',
            ], 422);
        }

        $requestRow = UserCreationRequest::create([
            'tenant_id' => $tenantId,
            'requested_by' => RequirePermission::resolveCentralUserId($request->user()),
            'name' => $validated['name'] ?? null,
            'email' => $email,
            'role' => $validated['role'],
            'note' => $validated['note'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Request submitted. The NoovaERP team will create the account and share the credentials.',
            'request' => $requestRow,
        ], 201);
    }
}
