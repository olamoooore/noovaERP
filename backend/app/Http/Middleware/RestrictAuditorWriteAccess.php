<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictAuditorWriteAccess
{
    /**
     * Routes/methods that auditors are allowed to POST/PATCH/PUT/DELETE on.
     */
    protected array $allowedWriteRoutes = [
        'api/v1/auditor/queries' => ['POST'],
        'api/v1/auditor/queries/*' => ['POST', 'PATCH'],
        'api/v1/auditor/exports' => ['POST'],
        'api/v1/auditor/profile' => ['PATCH'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->hasRole('Auditor')) {
            return $next($request);
        }

        $method = $request->method();
        $path = $request->path();

        // Allow read methods
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
            return $next($request);
        }

        // Check if this write operation is allowed for auditors
        foreach ($this->allowedWriteRoutes as $route => $methods) {
            $pattern = '#^' . preg_replace('#\\*#', '[^/]+', $route) . '$#';
            if (preg_match($pattern, $path) && in_array($method, $methods)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Auditors have read-only access. Write operations are not permitted.',
        ], 403);
    }
}
