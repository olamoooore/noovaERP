<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AccountingAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates accounting write operations (POST, PUT, PATCH, DELETE) behind
 * a single AccountingAccessService check.
 *
 * Read operations (GET, HEAD, OPTIONS) pass through for all authenticated users.
 * All access-decision logic lives in AccountingAccessService — not here.
 */
class EnsureAccountingWriteAccess
{
    public function __construct(
        private readonly AccountingAccessService $accessService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Read operations are allowed for all authenticated users
        if ($request->isMethod('GET') || $request->isMethod('HEAD') || $request->isMethod('OPTIONS')) {
            return $next($request);
        }

        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->accessService->canWrite($user)) {
            return response()->json([
                'message' => 'You do not have permission to perform accounting write operations.',
            ], 403);
        }

        return $next($request);
    }
}
