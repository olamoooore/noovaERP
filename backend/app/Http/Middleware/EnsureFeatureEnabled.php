<?php

namespace App\Http\Middleware;

use App\Models\Feature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        if (!Schema::hasTable('features') || !Schema::hasTable('tenant_features')) {
            abort(500, 'Feature flag tables are missing. Run tenant migrations.');
        }

        $feature = Feature::query()->where('key', $featureKey)->first();
        if (!$feature) {
            abort(403, 'Feature not available.');
        }

        if ($feature->is_core) {
            return $next($request);
        }

        if (!$feature->isEnabledForTenant()) {
            abort(403, 'Feature is not enabled for this tenant.');
        }

        return $next($request);
    }
}
