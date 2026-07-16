<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgentOrUser
{
    /**
     * Handle an incoming request.
     * Accepts both Sanctum tokens (for users) and custom agent tokens.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        \Log::info('AuthenticateAgentOrUser middleware', [
            'has_token' => ! empty($token),
            'token_preview' => $token ? substr($token, 0, 20).'...' : null,
            'url' => $request->url(),
        ]);

        if (! $token) {
            \Log::warning('No bearer token provided');

            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. No bearer token provided.',
            ], 401);
        }

        // Try Sanctum authentication first (for regular users)
        // Sanctum tokens are in format: {id}|{plaintext}
        // The token column stores: hash('sha256', {plaintext})
        if (str_contains($token, '|')) {
            // This is a Sanctum token
            [$id, $plaintext] = explode('|', $token, 2);

            \Log::info('Checking Sanctum token', ['token_id' => $id]);

            $accessToken = DB::table('public.personal_access_tokens')
                ->where('id', $id)
                ->where('token', hash('sha256', $plaintext))
                ->first();

            if ($accessToken) {
                \Log::info('Sanctum token found', ['user_id' => $accessToken->tokenable_id]);

                // Valid Sanctum token - get the user
                $user = DB::table('public.users')
                    ->where('id', $accessToken->tokenable_id)
                    ->first();

                if ($user) {
                    \Log::info('User authenticated', ['email' => $user->email]);

                    // Update last_used_at
                    DB::table('public.personal_access_tokens')
                        ->where('id', $id)
                        ->update(['last_used_at' => now()]);

                    // Set user on request for controllers to use
                    $request->setUserResolver(function () use ($user) {
                        return $user;
                    });

                    return $next($request);
                }
            }

            \Log::warning('Sanctum token not found or invalid');
        } else {
            \Log::info('Not a Sanctum token format, skipping Sanctum check');
        }

        // Try agent authentication (for agents)
        // Agents live in the tenant schema — resolve tenant from Host header first.
        $this->initTenantFromHost($request);
        $agent = DB::table('agents')
            ->where('api_token', $token)
            ->first();

        if ($agent) {
            \Log::info('Agent authenticated', ['agent_id' => $agent->id]);

            // Update agent heartbeat
            DB::table('agents')
                ->where('id', $agent->id)
                ->update([
                    'last_heartbeat' => now(),
                    'status' => 'online',
                    'updated_at' => now(),
                ]);

            // Attach agent to request
            $request->attributes->set('authenticated_agent', $agent);

            return $next($request);
        }

        \Log::warning('Authentication failed - neither user nor agent token valid');

        // Neither user nor agent authentication succeeded
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated. Invalid token.',
        ], 401);
    }

    /**
     * Resolve and initialize the tenant from the Host header.
     * Only runs when tenancy is not already initialized (agent requests bypass Sanctum).
     */
    private function initTenantFromHost(Request $request): void
    {
        if (function_exists('tenancy') && tenancy()->initialized) {
            return;
        }

        $host = $request->getHost();
        if (! $host) {
            return;
        }

        try {
            DB::statement('SET search_path TO public');
            $domain = DB::table('public.domains')->where('domain', $host)->first();

            if ($domain) {
                $tenant = \App\Models\Tenant::find($domain->tenant_id);
                if ($tenant) {
                    tenancy()->initialize($tenant);
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('AuthenticateAgentOrUser: Tenant resolution from Host header failed', [
                'host' => $host,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
