<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
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

            DB::statement('SET search_path TO public');

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

            DB::table('user_company_access')->updateOrInsert([
                'user_id' => $publicUser->id,
                'tenant_id' => $tenant->id,
            ], [
                'role' => 'owner',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

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
}
