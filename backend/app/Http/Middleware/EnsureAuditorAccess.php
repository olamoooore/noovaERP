<?php

namespace App\Http\Middleware;

use App\Models\Auditor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuditorAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Skip for non-auditor users (admins, owners, etc. can pass through)
        if (! $user->hasRole('Auditor')) {
            return $next($request);
        }

        // Get auditor record for this tenant
        $auditor = Auditor::where('tenant_id', tenant()->id)
            ->where('email', $user->email)
            ->where('status', 'active')
            ->first();

        if (! $auditor) {
            return response()->json(['message' => 'Auditor access not found or inactive.'], 403);
        }

        // Check time-based access
        $now = now();
        if ($auditor->access_starts_at && $now->lt($auditor->access_starts_at)) {
            return response()->json(['message' => 'Auditor access has not started yet.'], 403);
        }

        if ($auditor->access_ends_at && $now->gt($auditor->access_ends_at)) {
            // Auto-expire if past end date
            if ($auditor->status !== 'expired') {
                $auditor->update(['status' => 'expired']);
            }

            return response()->json(['message' => 'Auditor access has expired.'], 403);
        }

        // Load scopes onto request for downstream use
        $scopes = $auditor->scopes()->with('entity')->get();
        $request->attributes->set('auditor', $auditor);
        $request->attributes->set('auditor_scopes', $scopes);

        return $next($request);
    }
}
