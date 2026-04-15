<?php

namespace Modules\EventManagement\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Feature;
use App\Models\TenantFeature;
use App\SuperAdmin\Application\FeatureGateService;

class FeatureFlagMiddleware
{
    public function __construct(
        private readonly FeatureGateService $featureGate,
    ) {}

    /**
     * Handle an incoming request.
     * Checks the canonical entitlement system (TenantEntitlement) first,
     * falling back to legacy TenantFeature for backward compatibility.
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

        // Canonical path: check TenantEntitlement via FeatureGateService
        if (function_exists('tenant') && tenant()) {
            $tenantId = (string) tenant('id');
            $check = $this->featureGate->check($tenantId, $feature);

            if ($check['is_entitled']) {
                return $next($request);
            }
        }

        // Legacy fallback: check TenantFeature table
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
