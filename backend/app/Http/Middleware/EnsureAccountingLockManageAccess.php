<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AccountingAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureAccountingLockManageAccess
 *
 * Gates accounting LOCK MANAGEMENT operations (setting lock dates,
 * overriding locked periods) behind AccountingAccessService::canManageLocks() check.
 *
 * Requires accounting.lock permission OR admin/super-admin/Owner role.
 *
 * Workstream 2 (2026-04-15): Fine-grained entitlement enforcement.
 */
class EnsureAccountingLockManageAccess
{
    public function __construct(
        private readonly AccountingAccessService $accessService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->accessService->canManageLocks($user)) {
            return response()->json([
                'message' => 'You do not have permission to manage accounting lock dates.',
                'required_permission' => 'accounting.lock',
                'required_role' => 'admin, super-admin, or Owner',
            ], 403);
        }

        return $next($request);
    }
}
