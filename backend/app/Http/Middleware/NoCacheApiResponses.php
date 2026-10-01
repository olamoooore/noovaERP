<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Business API responses must never be cached by browsers or intermediaries.
 *
 * Without explicit directives a normal refresh can be served from a cached
 * copy (intermediary or browser) and the user must hard-refresh to see the
 * current data. Every API response therefore carries no-store/no-cache —
 * this covers tenant data, lists, metrics and the SuperAdmin console.
 */
class NoCacheApiResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}