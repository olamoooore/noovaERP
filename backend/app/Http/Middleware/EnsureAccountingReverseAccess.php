<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AccountingAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureAccountingReverseAccess
 *
 * Gates accounting REVERSAL operations (reversing posted entries) behind
 * AccountingAccessService::canReverse() check.
 *
 * Requires accounting.reverse permission OR admin/super-admin/Owner role.
 *
 * Workstream 2 (2026-04-15): Fine-grained entitlement enforcement.
 */
class EnsureAccountingReverseAccess
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

        if (! $this->accessService->canReverse($user)) {
            return response()->json([
                'message' => 'You do not have permission to reverse journal entries.',
                'required_permission' => 'accounting.reverse',
                'required_role' => 'admin, super-admin, or Owner',
            ], 403);
        }

        return $next($request);
    }
}
