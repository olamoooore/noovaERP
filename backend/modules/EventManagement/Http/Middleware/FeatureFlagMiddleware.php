<?php

namespace Modules\EventManagement\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Feature;
use App\Models\TenantFeature;

class FeatureFlagMiddleware
{
    /**
     * Handle an incoming request.
     * Checks the ERP's central Feature/TenantFeature system.
     * Core features (is_core = true) are always accessible.
     */
    public function handle(Request $request, Closure $next, string $feature = 'event_management'): Response
    {
        $featureRecord = Feature::where('key', $feature)->first();

        // Feature doesn't exist in DB yet — allow through (will be seeded)
        if (!$featureRecord) {
            return $next($request);
        }

        // Core features are always enabled
        if ($featureRecord->is_core) {
            return $next($request);
        }

        // Check tenant-specific toggle
        $tenantFeature = TenantFeature::where('feature_id', $featureRecord->id)->first();
        if ($tenantFeature && $tenantFeature->is_enabled) {
            return $next($request);
        }

        return response()->json([
            'message' => "Feature '{$feature}' is not enabled for this tenant",
            'error'   => 'feature_not_enabled',
        ], 403);
    }
}
