<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnsureTenantSearchPath
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Get the configured search_path
        $connectionName = DB::getDefaultConnection();
        $searchPath = config("database.connections.{$connectionName}.search_path");

        // If a tenant search_path is configured, ensure it's set on the current connection
        if ($searchPath && $searchPath !== 'public') {
            try {
                // Force set the search_path on the current connection
                DB::statement("SET search_path TO {$searchPath}");

                Log::debug('EnsureTenantSearchPath: Set search_path before controller', [
                    'search_path' => $searchPath,
                    'route' => $request->path(),
                ]);
            } catch (\Exception $e) {
                Log::error('EnsureTenantSearchPath: Failed to set search_path', [
                    'error' => $e->getMessage(),
                    'search_path' => $searchPath,
                ]);
            }
        }

        return $next($request);
    }
}
