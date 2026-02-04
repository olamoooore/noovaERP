<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsurePublicSchemaForAuth
{
    /**
     * Handle an incoming request.
     *
     * This middleware ensures that Sanctum token lookups happen in the public schema,
     * even when tenant middleware has switched the database context.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Before processing the request, set search_path to public for token lookups
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SET search_path TO public');
        }
        
        $response = $next($request);
        
        // After processing, we don't need to reset because tenant middleware
        // will set it again for subsequent queries if needed
        
        return $response;
    }
}
