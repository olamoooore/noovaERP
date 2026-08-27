<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgent
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. No bearer token provided.',
            ], 401);
        }

        // Check if token exists in agents table
        // Per Pillar 2 Part 3 §4.2: compare SHA-256 hash, not plaintext.
        $agent = DB::table('agents')
            ->where('api_token_hash', hash('sha256', $token))
            ->first();

        if (! $agent) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Invalid agent token.',
            ], 401);
        }

        // Update last heartbeat
        DB::table('agents')
            ->where('id', $agent->id)
            ->update([
                'last_heartbeat' => now(),
                'status' => 'online',
                'updated_at' => now(),
            ]);

        // Attach agent to request for use in controllers
        $request->attributes->set('authenticated_agent', $agent);

        return $next($request);
    }
}
