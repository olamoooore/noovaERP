<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue #85 — authenticates inbound-email webhook requests.
 *
 * The endpoint accepts payloads from an external IMAP poller (or a
 * hosted-mail-provider inbound webhook). Auth is a shared secret in the
 * `X-Inbound-Email-Token` header, matched against `INBOUND_EMAIL_TOKEN`
 * in the central `.env`. When the env var is unset the middleware fails
 * closed so an empty secret never lets requests through.
 */
class InboundEmailAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) env('INBOUND_EMAIL_TOKEN', '');
        if ($expected === '') {
            return response()->json([
                'error' => 'Inbound email bridge is not configured on this deployment.',
            ], 503);
        }

        $provided = (string) $request->header('X-Inbound-Email-Token', '');
        if (! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'Invalid token.'], 401);
        }

        return $next($request);
    }
}
