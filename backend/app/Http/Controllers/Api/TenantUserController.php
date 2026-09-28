<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\TenantRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Manage users within the current tenant context.
 */
class TenantUserController extends Controller
{
    public function __construct(
        private readonly TenantRoleService $roleService,
    ) {}

    /**
     * GET /api/v1/tenant-users
     *
     * List all users assigned to the current tenant with their roles.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant context required.'], 403);
        }

        $users = DB::table('public.user_company_access')
            ->join('public.users', 'users.id', '=', 'user_company_access.user_id')
            ->where('user_company_access.tenant_id', $tenantId)
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'user_company_access.role',
                'user_company_access.is_default',
                'user_company_access.last_accessed_at',
            ])
            ->orderBy('users.name')
            ->get()
            ->map(function ($row) use ($tenantId) {
                $role = $this->roleService->getSpatieRoleName($row->id, $tenantId);

                return [
                    'id' => $row->id,
                    'name' => $row->name,
                    'email' => $row->email,
                    'role' => $row->role,
                    'role_display' => $role,
                    'is_default' => (bool) $row->is_default,
                    'last_accessed_at' => $row->last_accessed_at,
                ];
            });

        return response()->json([
            'data' => $users,
        ]);
    }

    /**
     * PUT /api/v1/tenant-users/{id}/role
     *
     * Update a user's role within the current tenant.
     * Only Owner and Admin can change roles.
     */
    public function updateRole(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant context required.'], 403);
        }

        $currentUser = $request->user();

        if (! $currentUser) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Only Owner and Admin can change roles
        $currentRole = $this->roleService->getRole(\App\Http\Middleware\RequirePermission::resolveCentralUserId($currentUser), $tenantId);
        if (! $currentRole || ! in_array($currentRole->value, UserRole::superRoles(), true)) {
            return response()->json([
                'message' => 'You do not have permission to change user roles.',
            ], 403);
        }

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(UserRole::values())],
        ]);

        $affected = DB::table('public.user_company_access')
            ->where('user_id', $id)
            ->where('tenant_id', $tenantId)
            ->update([
                'role' => $validated['role'],
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            return response()->json([
                'message' => 'User not found in this tenant.',
            ], 404);
        }

        // Re-sync the tenant user's Spatie role on next request
        return response()->json([
            'message' => 'Role updated successfully.',
            'data' => [
                'user_id' => $id,
                'role' => $validated['role'],
            ],
        ]);
    }

    /**
     * DELETE /api/v1/tenant-users/{id}
     *
     * Remove a user from the current tenant.
     * Only Owner can remove users.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant context required.'], 403);
        }

        $currentUser = $request->user();

        if (! $currentUser) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Only Owner can remove users
        $currentRole = $this->roleService->getRole(\App\Http\Middleware\RequirePermission::resolveCentralUserId($currentUser), $tenantId);
        if ($currentRole !== UserRole::OWNER) {
            return response()->json([
                'message' => 'Only the tenant owner can remove users.',
            ], 403);
        }

        // Prevent self-removal
        if ($currentUser->id === $id) {
            return response()->json([
                'message' => 'You cannot remove yourself from the tenant.',
            ], 422);
        }

        $deleted = DB::table('public.user_company_access')
            ->where('user_id', $id)
            ->where('tenant_id', $tenantId)
            ->delete();

        if ($deleted === 0) {
            return response()->json([
                'message' => 'User not found in this tenant.',
            ], 404);
        }

        return response()->json([
            'message' => 'User removed from tenant successfully.',
        ]);
    }

    /**
     * POST /api/v1/tenant-users
     *
     * Invite or add a user to the current tenant.
     * If the email exists in central users, just add the tenant access row.
     * If not, create a new central user with a temporary password.
     */
    public function invite(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant context required.'], 403);
        }

        $currentUser = $request->user();

        if (! $currentUser) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Only Owner and Admin can invite users
        $currentRole = $this->roleService->getRole(\App\Http\Middleware\RequirePermission::resolveCentralUserId($currentUser), $tenantId);
        if (! $currentRole || ! in_array($currentRole->value, UserRole::superRoles(), true)) {
            return response()->json([
                'message' => 'You do not have permission to invite users.',
            ], 403);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in(UserRole::values())],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $email = strtolower($validated['email']);
        $role = $validated['role'];
        $name = $validated['name'] ?? explode('@', $email)[0];

        // Look up existing user in public schema
        $existingUser = DB::table('public.users')->where('email', $email)->first();

        if ($existingUser) {
            // Check if user already has access to this tenant
            $alreadyHasAccess = DB::table('public.user_company_access')
                ->where('user_id', $existingUser->id)
                ->where('tenant_id', $tenantId)
                ->exists();

            if ($alreadyHasAccess) {
                return response()->json([
                    'message' => 'User is already a member of this workspace.',
                ], 422);
            }

            DB::table('public.user_company_access')->insert([
                'user_id' => $existingUser->id,
                'tenant_id' => $tenantId,
                'role' => $role,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'message' => 'User added to workspace successfully.',
                'data' => [
                    'id' => $existingUser->id,
                    'name' => $existingUser->name,
                    'email' => $existingUser->email,
                    'role' => $role,
                ],
            ], 201);
        }

        // Create new central user
        // Pillar 2 Part 1 §3.4 / Pillar 1 P0-0: previously this row wrote
        // `'role' => 'platform_super_admin'` — combined with the column
        // schema default of `'platform_super_admin'`, any
        // tenant-admin could invite an not-previously-registered email
        // and the resulting user would silently receive a global SuperAdmin
        // bypass via RequirePermission's `$user->role === 'platform_super_admin'`
        // check. Per the report, the fix is to never grant privileged
        // roles via this flow; the role column is omitted entirely so
        // it falls through to NULL (per migration 2026_08_26_000001)
        // and `User::isSuperAdmin()` evaluates to false.
        $tempPassword = \Illuminate\Support\Str::random(16);
        $newUserId = DB::table('public.users')->insertGetId([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => null,
            'password' => \Illuminate\Support\Facades\Hash::make($tempPassword),
            // 'role' intentionally NOT set — DO NOT add `'role' => 'platform_super_admin'`
            // (or any other privileged value) here. Use
            // `User::assignAdminRole()` from `AdminUserService` if a
            // privileged role is genuinely required.
            //
            // 'is_active' intentionally NOT set — the central public.users
            // table (per 2025_12_15_160000_create_central_users_table.php)
            // does not have an is_active column. (The tenant-schema users
            // table does, but the central one doesn't.) Setting it here
            // would cause a QueryException.
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('public.user_company_access')->insert([
            'user_id' => $newUserId,
            'tenant_id' => $tenantId,
            'role' => $role,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Deliver an actual invitation: a set-password link the new member
        // can use to sign in (the temporary password above is never shared).
        // A mail outage must not roll back the membership, so failures are
        // reported via `email_sent` instead of a 500.
        $linkService = app(\App\Services\PasswordLinkService::class);
        $token = $linkService->issue($email);
        $emailSent = true;
        try {
            $linkService->send($email, $name, $token, 'invite', tenant('name'));
        } catch (\Throwable $e) {
            $emailSent = false;
            \Illuminate\Support\Facades\Log::warning('Tenant invite email failed to send', [
                'email' => $email,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => $emailSent
                ? 'Invitation sent. User created and added to workspace.'
                : 'User added to the workspace, but the invitation email could not be sent. Use "Forgot password" to resend the link.',
            'email_sent' => $emailSent,
            'data' => [
                'id' => $newUserId,
                'name' => $name,
                'email' => $email,
                'role' => $role,
            ],
        ], 201);
    }

    private function resolveTenantId(Request $request): ?string
    {
        if (function_exists('tenant') && tenant()) {
            $id = tenant('id');
            if ($id !== null && $id !== '') {
                return (string) $id;
            }
        }

        $headerTenantId = $request->header('X-Tenant-ID');
        if (is_string($headerTenantId) && $headerTenantId !== '') {
            return $headerTenantId;
        }

        return null;
    }
}
