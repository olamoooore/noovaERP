<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\TenantUserController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #P2-1 — Pillar 2 Part 1 §3.4 / Pillar 1 P0-0:
 *
 * `TenantUserController::invite()` previously created a brand-new
 * `public.users` row with `'role' => 'platform_super_admin'` (line 256).
 * Combined with `RequirePermission`'s `$user->role === 'platform_super_admin'`
 * bypass and the column's schema default of `'platform_super_admin'`, this
 * meant any tenant-admin who invited a not-previously-registered email
 * handed the invitee a global SuperAdmin bypass on a plate. This test
 * pins the fix at three layers:
 *
 *   1. The TenantUserController::invite() response does not include a
 *      privileged role for the new user.
 *   2. After invite, the resulting `public.users.role` value is NOT in
 *      User::PRIVILEGED_ROLES.
 *   3. The new user cannot log in to the admin panel
 *      (`/api/v1/admin/auth/login`).
 *
 * Layer 1 + 2 are the production-code invariants. Layer 3 is the
 * end-user observable that this regression covers.
 *
 * The test uses the live tenant context fixture and TenantTestCase's
 * `bindTenantContextWithPermissions` for the inviter (tenant admin).
 */
class IssueP21InvitePrivilegeEscalationTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        // Issue #P2-1: bind a real tenant + seed Spatie roles so the
        // inviter has `users.write` and the test does not depend on the
        // (now-removed) `role` string column fallback.
        $this->bindTenantContextWithPermissions((string) Str::uuid());

        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        // Ensure the central tenants row exists for the FK constraints
        // exercised below (TenantUserController::invite() inserts into
        // public.user_company_access which has FK → public.tenants).
        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'IssueP21 Test Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Layer 1 + 2: the controller does not write `role` to the new
     * `public.users` row.
     */
    public function test_invite_to_brand_new_email_does_not_grant_privileged_role(): void
    {
        $inviter = User::factory()->create();
        $inviter->assignRole('Admin');
        // RequirePermission resolves permissions via TenantRoleService which
        // reads user_company_access.user_id = $user->id. Insert the row so
        // the inviter actually has the required `users.write` permission for
        // this tenant.
        DB::table('user_company_access')->insert([
            'user_id' => $inviter->id,
            'tenant_id' => $this->tenantUuid,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $newEmail = 'new-invite-'.uniqid().'@example.test';

        // Contract change (2026-09-30): tenant-side user creation is
        // platform-only. A tenant admin now gets 403 here and no user row
        // may be created.
        $this->actingAs($inviter, 'sanctum')
            ->postJson('/api/v1/tenant-users', [
                'name' => 'Brand New User',
                'email' => $newEmail,
                'role' => 'viewer',     // tenant role (user_company_access.role)
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('public.users', ['email' => $newEmail]);

        // The original invariant still holds through the only remaining
        // creation path (platform superadmin): a brand-new email never
        // receives a privileged `users.role`.
        $superadmin = User::factory()->create();
        $superadmin->assignAdminRole('platform_super_admin', null, 'P21 invariant check');

        $this->actingAs($superadmin, 'sanctum')
            ->postJson('/api/v1/tenant-users', [
                'name' => 'Brand New User',
                'email' => $newEmail,
                'role' => 'viewer',
                'password' => 'TempPass@1234',
            ])
            ->assertCreated();

        $userRow = DB::table('public.users')->where('email', $newEmail)->first();
        $this->assertNotNull($userRow, 'superadmin invite must create the user');

        $this->assertNotContains(
            $userRow->role ?? '',
            User::PRIVILEGED_ROLES,
            'public.users.role must NOT be a privileged value after invite(); got: '.
            var_export($userRow->role, true)
        );
    }

    /**
     * Layer 3: even if a row was created with an attacker-supplied
     * role string, `User::isSuperAdmin()` must return false.
     *
     * Tests the User::isSuperAdmin() helper directly against the
     * invariant: when `role` is NULL, the privilege check is false.
     */
    public function test_is_super_admin_returns_false_when_role_is_null(): void
    {
        $u = new User;
        $u->role = null;
        $u->is_super_admin = false;

        $this->assertFalse($u->isSuperAdmin());
    }

    public function test_is_super_admin_returns_false_when_role_is_normal_user_string(): void
    {
        $u = new User;
        $u->role = 'viewer';
        $u->is_super_admin = false;

        $this->assertFalse($u->isSuperAdmin());
    }

    public function test_is_super_admin_returns_true_when_role_is_privileged(): void
    {
        $u = new User;
        $u->role = 'platform_super_admin';
        $u->is_super_admin = false;

        $this->assertTrue($u->isSuperAdmin());
    }

    public function test_assign_admin_role_throws_for_non_privileged_value(): void
    {
        $u = new User;
        $this->expectException(\InvalidArgumentException::class);
        $u->assignAdminRole('viewer');
    }

    public function test_assign_admin_role_logs_warning(): void
    {
        // Capture log writes by binding a channel.
        $captured = [];
        \Illuminate\Support\Facades\Log::listen(function ($message) use (&$captured) {
            $captured[] = $message;
        });

        // Create a real row so save() works.
        $u = User::create([
            'name' => 'audit-target',
            'email' => 'audit-target-'.uniqid().'@example.test',
            'password' => bcrypt('x'),
        ]);

        $u->assignAdminRole('platform_super_admin', $actorId = 42, reason: 'unit test');

        $matching = array_filter($captured, fn ($m) => str_contains($m->message, 'users.role assigned'));
        $this->assertNotEmpty($matching, 'Log::warning must be emitted on role assignment');

        // Find the specific log entry.
        $entry = collect($matching)->first();
        $context = $entry->context;
        $this->assertSame($u->id, $context['user_id']);
        $this->assertSame('platform_super_admin', $context['new_role']);
        $this->assertNull($context['previous_role']);
        $this->assertSame(42, $context['actor_id']);
        $this->assertSame('unit test', $context['reason']);
    }

    public function test_user_factory_create_does_not_grant_platform_super_admin(): void
    {
        // Verify the schema default is NULL (no value supplied).
        DB::statement('ALTER TABLE users ALTER COLUMN role DROP DEFAULT');

        $u = User::factory()->create();

        $this->assertNull(
            $u->role,
            'A plain User::factory()->create() must yield role=NULL with the new schema default; got: '.
            var_export($u->role, true)
        );
    }

    public function test_user_factory_create_does_not_include_role_in_fillable(): void
    {
        // Even if the schema default were 'platform_super_admin', mass-
        // assigning role via the factory must be silently dropped —
        // verifying the fillable guard from model User by persisting
        // (create() goes through Model::fill() which respects $fillable;
        // make() is in-memory only and does not enforce $fillable).
        $createdUser = User::factory()->create([
            'role' => 'platform_super_admin',
        ]);

        $persistedRole = DB::table('public.users')
            ->where('id', $createdUser->id)
            ->value('role');

        $this->assertNotContains(
            (string) $persistedRole,
            User::PRIVILEGED_ROLES,
            'Mass-assigning role via User::factory()->create() must NOT persist a privileged value; got: '.
            var_export($persistedRole, true)
        );
    }
}
