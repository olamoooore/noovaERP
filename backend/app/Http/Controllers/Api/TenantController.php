<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\SuperAdmin\Application\EntitlementService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\PlanFamily;
use App\SuperAdmin\Domain\PlanItemDefault;
use App\SuperAdmin\Domain\PlanVersion;
use App\SuperAdmin\Domain\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Central tenant management (non-tenant context)
 */
class TenantController extends Controller
{
    public function signup(Request $request)
    {
        $domain = strtolower($request->input('subdomain', '')) . '.' . config('app.domain', 'localhost');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:tenants,email',
            'company' => 'required|string|max:255',
            'subdomain' => [
                'required',
                'string',
                'max:63',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('domains', 'domain')->where(fn ($query) => $query->where('domain', $domain)),
            ],
            'password' => 'required|string|min:8',
        ]);

        $tenant = null;
        $tenantInitialized = false;

        try {
            DB::statement('SET search_path TO public');

            $tenant = Tenant::create([
                'id' => Str::uuid(),
                'name' => $validated['company'],
                'email' => $validated['email'],
                'plan' => 'trial',
                'trial_ends_at' => now()->addDays(14),
            ]);

            $tenant->domains()->create([
                'domain' => $domain,
            ]);

            $publicUser = User::firstOrCreate([
                'email' => $validated['email'],
            ], [
                'name' => $validated['name'],
                'password' => Hash::make($validated['password']),
            ]);

            if (! Hash::check($validated['password'], $publicUser->password)) {
                $publicUser->update([
                    'name' => $validated['name'],
                    'password' => Hash::make($validated['password']),
                ]);
            }

            DB::table('public.user_company_access')->updateOrInsert([
                'user_id' => $publicUser->id,
                'tenant_id' => $tenant->id,
            ], [
                'role' => 'owner',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Create trial subscription and entitlements
            $this->setupTrialSubscription($tenant);

            tenancy()->initialize($tenant);
            $tenantInitialized = true;

            $user = User::firstOrCreate([
                'email' => $validated['email'],
            ], [
                'name' => $validated['name'],
                'password' => Hash::make($validated['password']),
            ]);

            if (! Hash::check($validated['password'], $user->password)) {
                $user->update([
                    'name' => $validated['name'],
                    'password' => Hash::make($validated['password']),
                ]);
            }

            $ownerRole = Role::firstOrCreate([
                'name' => 'Owner',
                'guard_name' => 'web',
            ]);
            $user->assignRole($ownerRole);

            tenancy()->end();
            $tenantInitialized = false;

            return response()->json([
                'message' => 'Tenant created successfully',
                'tenant' => $tenant,
                'domain' => $tenant->domains()->first()->domain,
            ], 201);

        } catch (Throwable $e) {
            if ($tenantInitialized) {
                tenancy()->end();
            }

            return response()->json([
                'message' => 'Failed to create tenant',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a trial subscription and populate entitlements for a new tenant.
     */
    private function setupTrialSubscription(Tenant $tenant): void
    {
        // Ensure trial plan family exists
        $trialFamily = PlanFamily::firstOrCreate(
            ['key' => 'trial'],
            [
                'name' => 'Trial',
                'description' => 'Free trial — full access for 14 days.',
                'is_active' => true,
                'sort_order' => 0,
            ]
        );

        // Ensure trial plan version exists
        $trialVersion = PlanVersion::firstOrCreate(
            ['plan_family_id' => $trialFamily->id, 'version_label' => 'v1'],
            [
                'is_active' => true,
                'is_publicly_listed' => true,
                'description' => 'Trial version',
            ]
        );

        // Ensure plan_item_defaults exist for trial — include all active catalog items
        $activeItems = CatalogItem::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', now());
            })
            ->get();

        $now = now();
        foreach ($activeItems as $item) {
            PlanItemDefault::firstOrCreate(
                [
                    'plan_family_id' => $trialFamily->id,
                    'catalog_item_id' => $item->id,
                ],
                [
                    'availability' => PlanItemDefault::AVAILABILITY_INCLUDED,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        // Create trial subscription
        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_version_id' => $trialVersion->id,
            'status' => 'trialing',
            'trial_starts_at' => now(),
            'trial_ends_at' => now()->addDays(14),
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(14),
        ]);

        // Rebuild entitlements so FeatureGateService returns correct results
        $entitlementService = app(EntitlementService::class);
        $entitlementService->rebuild($tenant->id, null, 'trial_signup');
    }
}
