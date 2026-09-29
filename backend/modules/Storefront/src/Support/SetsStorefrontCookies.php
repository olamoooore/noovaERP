<?php

namespace Modules\Storefront\Support;

use Illuminate\Http\Request;
use Modules\Storefront\Services\AccountService;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared cookie plumbing for storefront auth responses (D3): httpOnly
 * session cookie + JS-readable double-submit CSRF cookie.
 */
trait SetsStorefrontCookies
{
    private function setAuthCookies(Response $response, array $auth, Request $request): Response
    {
        $response->headers->set('X-Storefront-CSRF', $auth['csrf_token']);

        return $response
            ->withCookie($this->sfCookie(AccountService::SESSION_COOKIE, $auth['session_token'], $request, true))
            ->withCookie($this->sfCookie(AccountService::CSRF_COOKIE, $auth['csrf_token'], $request, false));
    }

    private function sfCookie(string $name, string $value, Request $request, bool $httpOnly): Cookie
    {
        return cookie(
            $name,
            $value,
            60 * 24 * AccountService::SESSION_TTL_DAYS,
            '/',
            null,
            $request->secure() || app()->environment('production'),
            $httpOnly,
            false,
            'lax',
        );
    }

    private function forgetAuthCookies(Response $response): Response
    {
        return $response
            ->withCookie(cookie()->forget(AccountService::SESSION_COOKIE, '/'))
            ->withCookie(cookie()->forget(AccountService::CSRF_COOKIE, '/'));
    }
}
