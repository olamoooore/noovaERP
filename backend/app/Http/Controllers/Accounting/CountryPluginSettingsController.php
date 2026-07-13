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
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $tenantId = $user->tenant_id;
        if (!$tenantId) {
            return response()->json(['error' => 'No tenant context'], 400);
        }

        $tenant = DB::connection('pgsql')->table('tenants')->where('id', $tenantId)->first();
        if (!$tenant) {
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
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $tenantId = $user->tenant_id;
        if (!$tenantId) {
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

        DB::connection('pgsql')->table('tenants')
            ->where('id', $tenantId)
            ->update([
                'tenant_country_code' => $countryCode,
                'country_plugin_enabled' => $enabled,
                'updated_at' => now(),
            ]);

        $seeded = false;
        if ($seedData && $enabled) {
            try {
                tenancy()->initialize(tenancy()->find($tenantId));
                
                if ($countryCode === 'MY') {
                    $seeder = new \Database\Seeders\MalaysiaCountrySeeder();
                    $seeder->run();
                    $seeded = true;
                } elseif ($countryCode === 'SG') {
                    $seeder = new \Database\Seeders\SingaporeCountrySeeder();
                    $seeder->run();
                    $seeded = true;
                }
            } catch (\Throwable $e) {
                Log::warning('Country data seeding failed', [
                    'tenant_id' => $tenantId,
                    'country_code' => $countryCode,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'data' => [
                'tenant_country_code' => $countryCode,
                'country_plugin_enabled' => $enabled,
                'seeded' => $seeded,
            ],
        ]);
    }
}
