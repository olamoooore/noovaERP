<?php

namespace App\Http\Middleware;

use App\Models\Accounting\CountryConfiguration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate access to country-specific plugin routes.
 *
 * Usage in routes:
 *   Route::middleware(['auth:sanctum', 'country.plugin:SG'])->group(...)
 *   Route::middleware(['auth:sanctum', 'country.plugin:MY'])->group(...)
 *
 * Passes only when ALL of the following are true:
 *   1. The current tenant has tenant_country_code matching the requested country.
 *   2. The tenant's country_plugin_enabled flag is true.
 *   3. An enabled CountryConfiguration row exists for that country in the tenant schema.
 */
class EnsureCountryPluginEnabled
{
    public function handle(Request $request, Closure $next, string $countryCode): Response
    {
        $tenant = tenancy()->tenant;

        if (! $tenant) {
            abort(403, 'Tenant context not available.');
        }

        // 1. Check tenant_country_code matches the requested plugin country
        $tenantCountry = strtoupper($tenant->tenant_country_code ?? '');
        if ($tenantCountry !== strtoupper($countryCode)) {
            abort(403, "Country plugin '{$countryCode}' is not configured for this tenant. Tenant country: ".($tenantCountry ?: 'not set').'.');
        }

        // 2. Check master feature flag on the tenant
        if (! $tenant->country_plugin_enabled) {
            abort(403, 'Country compliance plugin is not enabled for this tenant.');
        }

        // 3. Check an active CountryConfiguration entry exists in the tenant DB
        $configured = CountryConfiguration::where('country_code', strtoupper($countryCode))
            ->where('is_enabled', true)
            ->exists();

        if (! $configured) {
            abort(403, "Country configuration for '{$countryCode}' is not enabled. Please configure it in Accounting > Compliance Settings.");
        }

        return $next($request);
    }
}
