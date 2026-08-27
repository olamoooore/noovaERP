<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SecurityIncidentService;
use App\Services\TenantRoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Look up user in public schema (central user management)
        // Ensure we're querying the public schema
        \DB::statement('SET search_path TO public');

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            $incidentService = app(SecurityIncidentService::class);
            $incidentService->recordFailedLogin($request->email);

            $recentAttempts = $incidentService->getRecentByIp($request->ip());
            if ($recentAttempts >= 4) {
                $incidentService->recordBruteForce($recentAttempts + 1, $request->email);
            }

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Get the user's default tenant from company access (public schema)
        $defaultCompany = \DB::table('public.user_company_access')
            ->where('user_id', $user->id)
            ->where('is_default', true)
            ->first();

        if (! $defaultCompany) {
            throw ValidationException::withMessages([
                'email' => ['No tenant configured for this user. Please contact your administrator.'],
            ]);
        }

        $tenant = \App\Models\Tenant::find($defaultCompany->tenant_id);

        if (! $tenant) {
            throw ValidationException::withMessages([
                'email' => ['No tenant configured for this user.'],
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        $roleService = app(TenantRoleService::class);
        $role = $roleService->getSpatieRoleName($user->id, $tenant->id);
        $permissions = $roleService->getPermissions($user->id, $tenant->id);

        return response()->json([
            'user' => $user,
            'token' => $token,
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'role' => $role,
            'permissions' => $permissions,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        // Resolve tenant-scoped role and permissions
        $tenantId = $request->header('X-Tenant-ID');
        if (! $tenantId && function_exists('tenant') && tenant()) {
            $tenantId = tenant('id');
        }

        $role = null;
        $permissions = [];

        if ($tenantId) {
            $roleService = app(TenantRoleService::class);
            $role = $roleService->getSpatieRoleName($user->id, $tenantId);
            $permissions = $roleService->getPermissions($user->id, $tenantId);
        }

        return response()->json([
            'user' => $user->load('roles.permissions'),
            'role' => $role,
            'permissions' => $permissions,
        ]);
    }
}
