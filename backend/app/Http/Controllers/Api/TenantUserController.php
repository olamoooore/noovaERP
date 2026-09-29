<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\TenantRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
                'user_company_access.created_at as added_at',
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
                    'added_at' => $row->added_at,
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
            // Two delivery modes: a set-password link by email (default) or a
            // password the admin sets here and shares out-of-band.
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
        ]);

        $email = strtolower($validated['email']);
        $role = $validated['role'];
        $name = $validated['name'] ?? explode('@', $email)[0];
        $providedPassword = $validated['password'] ?? null;

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

            $passwordSet = false;
            $emailSent = null;

            if ($providedPassword !== null) {
                DB::table('public.users')->where('id', $existingUser->id)->update([
                    'password' => \Illuminate\Support\Facades\Hash::make($providedPassword),
                    'updated_at' => now(),
                ]);
                $this->revokeUserTokens((int) $existingUser->id);
                $passwordSet = true;
            } else {
                // Existing account, newly granted workspace access: send a
                // set-password link so they are not left guessing credentials.
                $emailSent = $this->sendSetPasswordLink($existingUser->email, $existingUser->name, 'invite', $tenantId);
            }

            return response()->json([
                'message' => $passwordSet
                    ? 'User added to workspace with the password you set.'
                    : ($emailSent
                        ? 'User added to workspace, and a set-password link was emailed.'
                        : 'User added to the workspace, but the set-password email could not be sent. Use "Send password reset link" to retry.'),
                'password_set' => $passwordSet,
                'email_sent' => $emailSent,
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
        $tempPassword = $providedPassword ?? \Illuminate\Support\Str::random(16);
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

        // Deliver credentials: either the admin-set password (shared
        // out-of-band) or a set-password link the new member uses to sign in.
        // A mail outage must not roll back the membership, so failures are
        // reported via `email_sent` instead of a 500.
        if ($providedPassword !== null) {
            return response()->json([
                'message' => 'Member created with the password you set.',
                'password_set' => true,
                'email_sent' => null,
                'data' => [
                    'id' => $newUserId,
                    'name' => $name,
                    'email' => $email,
                    'role' => $role,
                ],
            ], 201);
        }

        $emailSent = $this->sendSetPasswordLink($email, $name, 'invite', $tenantId);

        return response()->json([
            'message' => $emailSent
                ? 'Invitation sent. User created and added to workspace.'
                : 'User added to the workspace, but the invitation email could not be sent. Use "Send password reset link" to retry.',
            'password_set' => false,
            'email_sent' => $emailSent,
            'data' => [
                'id' => $newUserId,
                'name' => $name,
                'email' => $email,
                'role' => $role,
            ],
        ], 201);
    }

    /**
     * GET /tenant-users/{id}/activity
     *
     * Recent activity for one workspace member, merged from every
     * actor-attributed log the tenant schema actually has. Sources are
     * existence-checked so a tenant without a given log table still works.
     * Owner/Admin only (the feed can expose financial actions).
     */
    public function activity(Request $request, int $id): JsonResponse
    {
        $guard = $this->requireSuperRole($request);

        if ($guard !== null) {
            return $guard;
        }

        $central = DB::table('public.users')->where('id', $id)->first();

        if (! $central) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Logs may store either the tenant-schema user id or the central id
        // depending on the writer — query both.
        $tenantUserId = Schema::hasTable('users')
            ? DB::table('users')->where('email', $central->email)->value('id')
            : null;

        $actorIds = array_values(array_unique(array_filter([
            $tenantUserId !== null ? (int) $tenantUserId : null,
            (int) $id,
        ])));

        $entries = collect();

        // Accounting document posts / reversals.
        if (Schema::hasTable('posting_audit_logs')) {
            $entries = $entries->merge(
                DB::table('posting_audit_logs')
                    ->whereIn('actor_user_id', $actorIds)
                    ->orderByDesc('id')
                    ->limit(25)
                    ->get()
                    ->map(fn ($row) => [
                        'source' => 'accounting',
                        'action' => $row->action,
                        'description' => ucfirst((string) $row->action).' '.str_replace('_', ' ', (string) $row->document_type).' #'.$row->document_id,
                        'at' => $row->created_at,
                    ])
            );
        }

        // Invoice lifecycle events.
        if (Schema::hasTable('invoice_activity_log')) {
            $entries = $entries->merge(
                DB::table('invoice_activity_log')
                    ->where('actor_type', 'user')
                    ->whereIn('actor_id', $actorIds)
                    ->orderByDesc('id')
                    ->limit(25)
                    ->get()
                    ->map(function ($row) {
                        $meta = json_decode((string) $row->metadata, true) ?: [];

                        return [
                            'source' => 'invoice',
                            'action' => $row->event,
                            'description' => 'Invoice '.($meta['invoice_number'] ?? '#'.$row->invoice_id).' — '.str_replace('_', ' ', (string) $row->event),
                            'at' => $row->created_at,
                        ];
                    })
            );
        }

        // Product catalogue edits.
        if (Schema::hasTable('product_audit_logs')) {
            $productNames = [];
            $entries = $entries->merge(
                DB::table('product_audit_logs')
                    ->whereIn('user_id', $actorIds)
                    ->orderByDesc('id')
                    ->limit(25)
                    ->get()
                    ->map(function ($row) use (&$productNames) {
                        if (! isset($productNames[$row->product_id])) {
                            $productNames[$row->product_id] = DB::table('products')->where('id', $row->product_id)->value('name') ?? ('Product #'.$row->product_id);
                        }

                        return [
                            'source' => 'product',
                            'action' => $row->action,
                            'description' => $productNames[$row->product_id].' — '.$row->action.($row->field_name ? ' ('.$row->field_name.')' : ''),
                            'at' => $row->created_at,
                        ];
                    })
            );
        }

        // Chatter activities the member created.
        if (Schema::hasTable('chatter_activities')) {
            $entries = $entries->merge(
                DB::table('chatter_activities')
                    ->whereIn('created_by_id', $actorIds)
                    ->orderByDesc('id')
                    ->limit(25)
                    ->get()
                    ->map(fn ($row) => [
                        'source' => 'chatter',
                        'action' => $row->activity_type ?? 'activity',
                        'description' => $row->summary ?: ($row->note ?: 'Logged an activity'),
                        'at' => $row->created_at,
                    ])
            );
        }

        // Notifications delivered to the member.
        if (Schema::hasTable('notification_logs')) {
            $entries = $entries->merge(
                DB::table('notification_logs')
                    ->whereIn('user_id', $actorIds)
                    ->orderByDesc('id')
                    ->limit(15)
                    ->get()
                    ->map(fn ($row) => [
                        'source' => 'notification',
                        'action' => $row->event,
                        'description' => ($row->subject ?: $row->event).($row->status ? ' · '.$row->status : ''),
                        'at' => $row->created_at,
                    ])
            );
        }

        // System audit log entries attributed to the member.
        if (Schema::hasTable('platform_system_logs')) {
            $entries = $entries->merge(
                DB::table('platform_system_logs')
                    ->whereIn('user_id', $actorIds)
                    ->orderByDesc('id')
                    ->limit(25)
                    ->get()
                    ->map(fn ($row) => [
                        'source' => 'system',
                        'action' => $row->category,
                        'description' => $row->message,
                        'at' => $row->created_at,
                    ])
            );
        }

        $feed = $entries
            ->filter(fn ($entry) => ! empty($entry['at']))
            ->sortByDesc('at')
            ->take(50)
            ->values();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $central->id,
                    'name' => $central->name,
                    'email' => $central->email,
                ],
                'entries' => $feed,
            ],
        ]);
    }

    /**
     * Email an admin-triggered set-password link to a workspace member.
     * Owner/Admin only — same guard as invite.
     */
    public function sendPasswordLink(Request $request, int $id): JsonResponse
    {
        $guard = $this->requireSuperRole($request);

        if ($guard !== null) {
            return $guard;
        }

        $user = DB::table('public.users')->where('id', $id)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $emailSent = $this->sendSetPasswordLink($user->email, $user->name, 'reset', $this->resolveTenantId($request));

        return response()->json([
            'message' => $emailSent
                ? 'Password reset link emailed.'
                : 'The email could not be sent. Check the mail configuration and try again.',
            'email_sent' => $emailSent,
        ]);
    }

    /**
     * Set a member's password directly (Owner/Admin). Revokes their existing
     * sessions so the old credential cannot keep an authenticated session.
     */
    public function setPassword(Request $request, int $id): JsonResponse
    {
        $guard = $this->requireSuperRole($request);

        if ($guard !== null) {
            return $guard;
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $user = DB::table('public.users')->where('id', $id)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        DB::table('public.users')->where('id', $id)->update([
            'password' => \Illuminate\Support\Facades\Hash::make($validated['password']),
            'updated_at' => now(),
        ]);

        $this->revokeUserTokens($id);

        return response()->json(['message' => 'Password updated. The member will need to sign in again.']);
    }

    /**
     * Owner/Admin guard shared by invite/sendPasswordLink/setPassword.
     * Returns a 401/403 response when the caller may not manage members.
     */
    private function requireSuperRole(Request $request): ?JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant context required.'], 403);
        }

        $currentUser = $request->user();

        if (! $currentUser) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $currentRole = $this->roleService->getRole(
            \App\Http\Middleware\RequirePermission::resolveCentralUserId($currentUser),
            $tenantId,
        );

        if (! $currentRole || ! in_array($currentRole->value, UserRole::superRoles(), true)) {
            return response()->json(['message' => 'You do not have permission to manage members.'], 403);
        }

        return null;
    }

    /**
     * Issue + email a set-password link. Never throws — a mail failure is a
     * reported state, not a failed membership change.
     */
    private function sendSetPasswordLink(string $email, ?string $name, string $variant, ?string $tenantId): bool
    {
        try {
            $linkService = app(\App\Services\PasswordLinkService::class);
            $token = $linkService->issue($email);
            $linkService->send($email, $name, $token, $variant, tenant('name'));

            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Tenant member password link failed to send', [
                'email' => $email,
                'tenant_id' => $tenantId,
                'variant' => $variant,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Drop the member's Sanctum tokens so a password change ends old sessions.
     */
    private function revokeUserTokens(int $userId): void
    {
        try {
            DB::table('public.personal_access_tokens')
                ->where('tokenable_id', $userId)
                ->delete();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::debug('Token revoke after password change failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
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
