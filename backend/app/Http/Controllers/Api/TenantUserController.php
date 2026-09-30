<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\TenantMemberService;
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
        private readonly TenantMemberService $members,
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

        // Tenant-side user creation is restricted to the NoovaERP platform
        // team. Owners/admins raise a "Request new user" instead (see
        // UserRequestController); the platform approves it in the
        // SuperAdmin console, which calls the same TenantMemberService.
        if (! $currentUser->isSuperAdmin()) {
            return response()->json([
                'message' => 'Creating users is reserved for the NoovaERP platform team. Use "Request new user" and the team will create the account.',
            ], 403);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in(UserRole::values())],
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
        ]);

        $result = $this->members->createOrAttachUser(
            $tenantId,
            (string) $validated['email'],
            (string) $validated['role'],
            $validated['name'] ?? null,
            $validated['password'] ?? null,
        );

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'message' => $result['message'] ?? 'Unable to create the user.',
            ], 422);
        }

        return response()->json([
            'message' => $result['message'],
            'password_set' => $result['password_set'] ?? false,
            'email_sent' => $result['email_sent'] ?? null,
            'data' => [
                'id' => $result['user_id'],
                'name' => $result['name'],
                'email' => $result['email'],
                'role' => $result['role'],
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

        $emailSent = $this->members->sendSetPasswordLink($user->email, $user->name, 'reset', $this->resolveTenantId($request));

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

        $this->members->revokeUserTokens($id);

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
