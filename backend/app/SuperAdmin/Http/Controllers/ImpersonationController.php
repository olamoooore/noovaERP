<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Impersonation;
use App\Models\User;
use App\Services\SecurityIncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImpersonationController extends Controller
{
    private const MAX_DURATION_MINUTES = 60;

    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'tenant_id' => 'required|string|max:100',
            'reason' => 'nullable|string|max:500',
        ]);

        $actor = $request->user();
        $targetUser = User::findOrFail($request->user_id);

        // Reject self-impersonation
        if ($actor->id === $targetUser->id) {
            return response()->json([
                'message' => 'Cannot impersonate yourself.',
            ], 422);
        }

        // Verify target user has access to the specified tenant
        $hasAccess = DB::table('public.user_company_access')
            ->where('user_id', $targetUser->id)
            ->where('tenant_id', $request->tenant_id)
            ->exists();

        if (! $hasAccess) {
            return response()->json([
                'message' => 'Target user does not have access to the specified tenant.',
            ], 422);
        }

        // Expire any existing active impersonation for this actor
        Impersonation::where('actor_id', $actor->id)
            ->where('status', 'active')
            ->update(['status' => 'expired', 'ended_at' => now()]);

        $token = Str::random(64);

        $impersonation = Impersonation::create([
            'actor_id' => $actor->id,
            'target_user_id' => $targetUser->id,
            'tenant_id' => $request->tenant_id,
            'token' => hash('sha256', $token),
            'started_at' => now(),
            'expires_at' => now()->addMinutes(self::MAX_DURATION_MINUTES),
            'reason' => $request->reason,
            'ip_address' => $request->ip(),
            'status' => 'active',
        ]);

        app(SecurityIncidentService::class)->recordImpersonationStart(
            actorId: $actor->id,
            targetUserId: $targetUser->id,
            tenantId: $request->tenant_id,
        );

        return response()->json([
            'impersonation_token' => $token,
            'expires_at' => $impersonation->expires_at->toIso8601String(),
            'target_user' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
            ],
            'tenant_id' => $request->tenant_id,
        ]);
    }

    public function end(Request $request): JsonResponse
    {
        $request->validate([
            'impersonation_id' => 'required|integer|exists:impersonations,id',
        ]);

        $impersonation = Impersonation::findOrFail($request->impersonation_id);

        if ($impersonation->status !== 'active') {
            return response()->json([
                'message' => 'This impersonation session is already ended.',
            ], 422);
        }

        $impersonation->update([
            'status' => 'ended',
            'ended_at' => now(),
        ]);

        app(SecurityIncidentService::class)->recordImpersonationEnd(
            actorId: $impersonation->actor_id,
            targetUserId: $impersonation->target_user_id,
            tenantId: $impersonation->tenant_id,
        );

        return response()->json(['message' => 'Impersonation ended.']);
    }

    public function active(Request $request): JsonResponse
    {
        $actor = $request->user();

        $impersonation = Impersonation::where('actor_id', $actor->id)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->first();

        if (! $impersonation) {
            return response()->json(['active' => false]);
        }

        return response()->json([
            'active' => true,
            'impersonation_id' => $impersonation->id,
            'expires_at' => $impersonation->expires_at->toIso8601String(),
        ]);
    }
}
