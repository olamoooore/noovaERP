<?php

namespace Modules\Storefront\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Storefront\Services\AccountService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cookie-session guard for storefront ACCOUNT endpoints (D3).
 *
 * - FR-ID-08: the session AND the account's current status/verification
 *   are re-resolved through AccountService on EVERY request — suspension
 *   takes effect immediately, not at next login.
 * - FR-ID-09 (double-submit CSRF): any mutating request that arrives with
 *   an active session must echo the sf_csrf cookie in X-CSRF-Token.
 *   SameSite=Lax blocks cross-site XHR from carrying the session cookie at
 *   all; this is the second layer. (D3a bot resistance on login/signup is a
 *   separate, still-open decision.)
 */
final class StorefrontAccountAuth
{
    public function __construct(
        private readonly AccountService $accounts,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(AccountService::SESSION_COOKIE);
        $resolved = $this->accounts->resolveSession(is_string($token) ? $token : null);

        if ($resolved === null) {
            return response()->json([
                'message' => 'Storefront authentication required.',
                'code' => 'storefront_unauthenticated',
            ], 401);
        }

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $csrfCookie = $request->cookie(AccountService::CSRF_COOKIE);
            $csrfHeader = $request->header('X-CSRF-Token');

            if (! is_string($csrfCookie) || ! is_string($csrfHeader) || ! hash_equals($csrfCookie, $csrfHeader)) {
                return response()->json([
                    'message' => 'CSRF token missing or invalid.',
                    'code' => 'storefront_csrf_mismatch',
                ], 419);
            }
        }

        $request->attributes->set('storefront_account', $resolved['account']);
        $request->attributes->set('storefront_session', $resolved['session']);

        return $next($request);
    }
}
