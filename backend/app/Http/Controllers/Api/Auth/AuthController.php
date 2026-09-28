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
            'email' => 'required|string|max:255',
            'password' => 'required|string',
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

        // Role/permission reads must run against the TENANT schema — this
        // endpoint forced search_path to public, where Spatie holds a stale
        // role matrix (e.g. Sales appearing to have reports.view), which made
        // the SPA grant access the tenant actually denies. Initialize the
        // tenant just for the reads and restore the context afterwards.
        $role = null;
        $permissions = [];
        $initializedHere = false;
        if (! function_exists('tenant') || ! tenant()) {
            tenancy()->initialize($tenant);
            $initializedHere = true;
        }

        try {
            $roleService = app(TenantRoleService::class);
            $role = $roleService->getSpatieRoleName($user->id, $tenant->id);
            $permissions = $roleService->getPermissions($user->id, $tenant->id);
        } finally {
            if ($initializedHere) {
                tenancy()->end();
            }
        }

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

    /**
     * POST /api/v1/auth/forgot-password
     *
     * Emails a set-password link. Always answers with the same generic
     * message so the endpoint can't be used to probe which emails exist.
     */
    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = strtolower($validated['email']);
        $user = \Illuminate\Support\Facades\DB::table('public.users')->where('email', $email)->first();

        if ($user) {
            $linkService = app(\App\Services\PasswordLinkService::class);
            $token = $linkService->issue($email);
            try {
                $linkService->send($email, $user->name, $token, 'reset');
            } catch (\Throwable $e) {
                // Never leak transport state through this endpoint — log only.
                \Illuminate\Support\Facades\Log::warning('Password reset email failed to send', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'If that email is registered, a reset link has been sent.',
        ]);
    }

    /**
     * POST /api/v1/auth/reset-password
     *
     * Consumes a set-password link (forgot-password or tenant invite).
     */
    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $linkService = app(\App\Services\PasswordLinkService::class);

        if (! $linkService->consume(strtolower($validated['email']), $validated['token'], $validated['password'])) {
            throw ValidationException::withMessages([
                'token' => ['This password link is invalid or has expired. Request a new one.'],
            ]);
        }

        return response()->json([
            'message' => 'Password updated successfully. You can now sign in.',
        ]);
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
            // After SyncTenantUserFromCentralAuth the authenticated instance is
            // the TENANT-schema user, but public.user_company_access stores the
            // CENTRAL user id — resolve it by email exactly like
            // RequirePermission does, otherwise every role lookup misses and
            // the SPA receives role=null / permissions=[].
            $centralUserId = (int) (\Illuminate\Support\Facades\DB::table('public.users')
                ->where('email', $user->email)
                ->value('id') ?? $user->id);

            // Reading Spatie roles requires the tenant schema to be the active
            // search_path. Tenant routes already have it; the central /auth/me
            // route runs on public, so initialize the tenant just for the
            // permission reads and restore the previous context afterwards.
            $tenant = \App\Models\Tenant::find($tenantId);
            $initializedHere = false;
            if ($tenant && (! function_exists('tenant') || ! tenant())) {
                tenancy()->initialize($tenant);
                $initializedHere = true;
            }

            try {
                $roleService = app(TenantRoleService::class);
                $role = $roleService->getSpatieRoleName($centralUserId, $tenantId);
                $permissions = $roleService->getPermissions($centralUserId, $tenantId);
            } finally {
                if ($initializedHere) {
                    tenancy()->end();
                }
            }
        }

        return response()->json([
            'user' => $user->load('roles.permissions'),
            'role' => $role,
            'permissions' => $permissions,
        ]);
    }
}
