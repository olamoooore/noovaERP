<?php

namespace Modules\EventManagement\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Modules\EventManagement\Services\FeatureFlagController;
use Modules\EventManagement\Exceptions\FeatureNotEnabledException;

class FeatureFlagMiddleware
{
    protected FeatureFlagController $featureFlagController;

    public function __construct(FeatureFlagController $featureFlagController)
    {
        $this->featureFlagController = $featureFlagController;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature = 'event_management'): Response
    {
        $tenantId = $this->getTenantId($request);
        
        if (!$this->featureFlagController->hasFeature($tenantId, $feature)) {
            throw new FeatureNotEnabledException("Feature '{$feature}' is not enabled for this tenant");
        }

        return $next($request);
    }

    /**
     * Extract tenant ID from request
     */
    protected function getTenantId(Request $request): string
    {
        // In a real implementation, this would extract tenant ID from:
        // - JWT token
        // - Subdomain
        // - Header
        // - Database lookup based on user
        
        // For now, return a default tenant ID or from header
        return $request->header('X-Tenant-ID', 'default');
    }
}