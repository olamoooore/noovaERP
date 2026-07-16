<?php

namespace Modules\FinancialClose\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\FinancialClose\Services\FinancialCloseSignoffService;

class SignoffController
{
    public function __construct(
        private FinancialCloseSignoffService $signoffService,
    ) {}

    /**
     * List signoffs for a review.
     */
    public function index(string $reviewId): JsonResponse
    {
        $tenantId = tenant('id');
        if (! $tenantId) {
            return response()->json(['message' => 'Tenant context required'], 400);
        }

        $signoffs = $this->signoffService->listSignoffs($tenantId, $reviewId);

        return response()->json([
            'data' => $signoffs,
        ]);
    }

    /**
     * Record a sign-off on a review.
     * Requires accounting.lock permission (or admin/super-admin).
     */
    public function store(Request $request, string $reviewId): JsonResponse
    {
        $user = Auth::user();

        // Permission check: sign-off requires lock management permission
        $hasPermission = $user?->is_super_admin ?? false;
        if (! $hasPermission && $user && method_exists($user, 'hasPermissionTo')) {
            try {
                $hasPermission = $user->hasPermissionTo('accounting.lock');
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
                $hasPermission = false;
            }
        }
        if (! $hasPermission && $user && method_exists($user, 'hasRole')) {
            $hasPermission = $user->hasRole(['admin', 'super-admin', 'Owner']);
        }

        if (! $hasPermission) {
            return response()->json([
                'message' => 'You do not have permission to sign off reviews.',
                'required_permission' => 'accounting.lock',
            ], 403);
        }

        $validated = $request->validate([
            'role' => 'required|string|max:100',
            'notes' => 'nullable|string',
        ]);

        $tenantId = tenant('id');
        if (! $tenantId) {
            return response()->json(['message' => 'Tenant context required'], 400);
        }

        $signoff = $this->signoffService->recordSignoff(
            $tenantId,
            $reviewId,
            (string) $user->id,
            $validated['role'],
            $validated['notes'] ?? null,
        );

        if (! $signoff) {
            return response()->json([
                'message' => 'Unable to record sign-off. Review may not be in a signable state or SoD conflict exists.',
            ], 422);
        }

        return response()->json([
            'message' => 'Sign-off recorded successfully',
            'data' => $signoff,
        ], 201);
    }

    /**
     * Revoke a sign-off.
     */
    public function revoke(string $signoffId): JsonResponse
    {
        $user = Auth::user();

        // Same permission gate as recording
        $hasPermission = $user?->is_super_admin ?? false;
        if (! $hasPermission && $user && method_exists($user, 'hasPermissionTo')) {
            try {
                $hasPermission = $user->hasPermissionTo('accounting.lock');
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
                $hasPermission = false;
            }
        }
        if (! $hasPermission && $user && method_exists($user, 'hasRole')) {
            $hasPermission = $user->hasRole(['admin', 'super-admin', 'Owner']);
        }

        if (! $hasPermission) {
            return response()->json([
                'message' => 'You do not have permission to revoke sign-offs.',
                'required_permission' => 'accounting.lock',
            ], 403);
        }

        $tenantId = tenant('id');
        if (! $tenantId) {
            return response()->json(['message' => 'Tenant context required'], 400);
        }

        $success = $this->signoffService->revokeSignoff($tenantId, $signoffId, (string) $user->id);

        if (! $success) {
            return response()->json([
                'message' => 'Unable to revoke sign-off. It may not exist or the review is locked.',
            ], 422);
        }

        return response()->json([
            'message' => 'Sign-off revoked successfully',
        ]);
    }
}
