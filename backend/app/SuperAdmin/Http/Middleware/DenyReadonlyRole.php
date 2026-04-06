<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DenyReadonlyRole
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role === 'support_readonly') {
            return response()->json([
                'message' => 'Your role does not have permission to perform this action.',
            ], 403);
        }

        return $next($request);
    }
}
