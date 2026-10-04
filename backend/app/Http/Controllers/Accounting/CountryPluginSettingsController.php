<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CountryPluginSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $tenantId = tenant('id');
        if (! $tenantId) {
            return response()->json(['error' => 'No tenant context'], 400);
        }

        $tenant = DB::connection('pgsql')->table('tenants')->where('id', $tenantId)->first();
        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        return response()->json([
            'tenant_id' => $tenant->id,
            'tenant_country_code' => $tenant->tenant_country_code ?? null,
            'country_plugin_enabled' => (bool) ($tenant->country_plugin_enabled ?? false),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $tenantId = tenant('id');
        if (! $tenantId) {
            return response()->json(['error' => 'No tenant context'], 400);
        }

        $validated = $request->validate([
            'tenant_country_code' => 'required|string|in:SG,MY',
            'country_plugin_enabled' => 'required|boolean',
            'seed_country_data' => 'sometimes|boolean',
        ]);

        $countryCode = strtoupper($validated['tenant_country_code']);
        $enabled = $validated['country_plugin_enabled'];
        $seedData = $validated['seed_country_data'] ?? false;

        try {
            // #769 (A9) AC2/AC4: applying a country pack is one transaction.
            // Failures propagate to the caller instead of being logged away —
            // the old catch-and-continue returned 200 with seeded=false while
            // the tenant row and CountryConfiguration had already changed.
            // Postgres DDL is transactional, so the seeders' schema steps roll
            // back with everything else (no half-applied pack).
            return DB::connection('pgsql')->transaction(function () use ($tenantId, $countryCode, $enabled, $seedData) {
                DB::connection('pgsql')->table('tenants')
                    ->where('id', $tenantId)
                    ->update([
                        'tenant_country_code' => $countryCode,
                        'country_plugin_enabled' => $enabled,
                        'updated_at' => now(),
                    ]);

                $seeded = false;
                if ($seedData && $enabled) {
                    // Container-resolved: the seeding step is swappable and
                    // testable, and its failures are not swallowed.
                    $seeder = app(match ($countryCode) {
                        'MY' => \Database\Seeders\MalaysiaCountrySeeder::class,
                        'SG' => \Database\Seeders\SingaporeCountrySeeder::class,
                    });
                    $seeder->run();
                    $seeded = true;
                }

                if ($enabled) {
                    \App\Models\Accounting\CountryConfiguration::updateOrCreate(
                        ['tenant_id' => $tenantId, 'country_code' => $countryCode, 'provider_name' => 'default'],
                        ['is_enabled' => true, 'settings' => ['auto_submit' => false]]
                    );
                }

                return response()->json([
                    'data' => [
                        'tenant_country_code' => $countryCode,
                        'country_plugin_enabled' => $enabled,
                        'seeded' => $seeded,
                    ],
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Country plugin settings update failed', [
                'tenant_id' => $tenantId,
                'country_code' => $countryCode,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Country data could not be applied; nothing was changed.',
                'detail' => $e->getMessage(),
            ], 422);
        }
    }
}
