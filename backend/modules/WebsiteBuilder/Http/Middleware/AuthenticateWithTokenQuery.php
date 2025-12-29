<?php

namespace Modules\WebsiteBuilder\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class AuthenticateWithTokenQuery
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->has('token')) {
            $token = PersonalAccessToken::findToken($request->query('token'));
            
            if ($token && $token->tokenable) {
                Auth::login($token->tokenable);
            }
        }

        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
