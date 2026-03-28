<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Central tenant management (non-tenant context)
 */
class TenantController extends Controller
{
    public function signup(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:tenants,email',
            'company' => 'required|string|max:255',
            'subdomain' => 'required|string|max:63|unique:domains,domain|regex:/^[a-z0-9-]+$/',
            'password' => 'required|string|min:8',
        ]);

        try {
            // Create tenant
            $tenant = Tenant::create([
                'id' => Str::uuid(),
                'name' => $validated['company'],
                'email' => $validated['email'],
                'plan' => 'trial',
                'trial_ends_at' => now()->addDays(14),
            ]);

            // Create domain
            $tenant->domains()->create([
                'domain' => $validated['subdomain'] . '.' . config('app.domain', 'localhost'),
            ]);

            // Create user in public schema (for authentication)
            \DB::statement('SET search_path TO public');
            $publicUser = \App\Models\User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            // Link user to tenant
            \DB::table('user_company_access')->insert([
                'user_id' => $publicUser->id,
                'tenant_id' => $tenant->id,
                'role' => 'owner',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Initialize tenant (run migrations)
            tenancy()->initialize($tenant);

            // Create admin user in tenant schema
            $user = \App\Models\User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            // Assign owner role
            $ownerRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Owner']);
            $user->assignRole($ownerRole);

            tenancy()->end();

            return response()->json([
                'message' => 'Tenant created successfully',
                'tenant' => $tenant,
                'domain' => $tenant->domains()->first()->domain,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create tenant',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
