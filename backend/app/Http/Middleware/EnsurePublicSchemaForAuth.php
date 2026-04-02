<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsurePublicSchemaForAuth
{
    /**
     * Handle an incoming request.
     *
     * This middleware ensures that Sanctum token lookups happen in the public schema,
     * even when tenant middleware has switched the database context.
     *
     * Note: This only applies to Sanctum auth (auth:sanctum). WebCustomerAuth uses
     * tenant-scoped sessions, so tenant must be initialized first.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only set public schema for Sanctum auth routes
        // WebCustomerAuth routes need tenant context first
        $isWebCustomerRoute = str_contains($request->path(), 'web-shop/') 
            && !str_contains($request->path(), 'web-shop/auth/')
            && !str_contains($request->path(), 'web-shop/checkout/')
            && !str_contains($request->path(), 'web-shop/payment-gateways')
            && !str_contains($request->path(), 'web-shop/orders/guest/');
        
        if (!$isWebCustomerRoute) {
            try {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::statement('SET search_path TO public');
                }
            } catch (\Exception $e) {
                Log::warning('EnsurePublicSchemaForAuth: Failed to set public schema', [
                    'path' => $request->path(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        $response = $next($request);
        
        return $response;
    }
}
