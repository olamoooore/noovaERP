<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AccountingAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureAccountingPostAccess
 *
 * Gates accounting POST operations (posting drafts to GL) behind
 * AccountingAccessService::canPost() check.
 *
 * Requires accounting.post permission OR admin/super-admin/Owner role.
 *
 * Workstream 2 (2026-04-15): Fine-grained entitlement enforcement.
 */
class EnsureAccountingPostAccess
{
    public function __construct(
        private readonly AccountingAccessService $accessService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (!$this->accessService->canPost($user)) {
            return response()->json([
                'message' => 'You do not have permission to post journal entries.',
                'required_permission' => 'accounting.post',
                'required_role' => 'admin, super-admin, or Owner',
            ], 403);
        }

        return $next($request);
    }
}
