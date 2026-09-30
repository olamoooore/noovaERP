<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PasswordLinkMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Team members: credential delivery and "Last access" tracking.
 *
 * Covers the invite delivery modes (admin-set password vs emailed
 * set-password link), the Owner/Admin reset-link + set-password actions, and
 * the throttled last_accessed_at touch that fills the team list's Last access
 * column (previously never written, so every member showed "Never").
 */
class TenantUserInvitePasswordTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Invite Password Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * User creation is platform-only since 2026-09-30; invitations in tests
     * go through a platform superadmin (the only role the endpoint accepts).
     */
    private function makePlatformAdmin(): User
    {
        $user = User::factory()->create();

        if ($user->role !== 'platform_super_admin') {
            $user->assignAdminRole('platform_super_admin', null, 'TenantUserInvitePasswordTest');
        }

        return $user;
    }

    private function makeMember(string $accessRole, string $spatieRole): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole);

        DB::table('public.user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantUuid,
            'role' => $accessRole,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    public function test_invite_with_a_password_sets_it_and_skips_the_email(): void
    {
        Mail::fake();
        $superadmin = $this->makePlatformAdmin();

        $email = 'invitee-'.uniqid().'@example.test';

        $this->actingAs($superadmin, 'sanctum')
            ->postJson('/api/v1/tenant-users', [
                'email' => $email,
                'name' => 'Invited Person',
                'role' => 'viewer',
                'password' => 'SetByAdmin@123',
            ])
            ->assertCreated()
            ->assertJsonPath('password_set', true);

        $created = DB::table('public.users')->where('email', $email)->first();
        $this->assertNotNull($created);
        $this->assertTrue(Hash::check('SetByAdmin@123', $created->password));
        Mail::assertNotSent(PasswordLinkMail::class);
    }

    public function test_invite_without_a_password_emails_a_set_password_link(): void
    {
        Mail::fake();
        $superadmin = $this->makePlatformAdmin();

        $email = 'invitee-'.uniqid().'@example.test';

        $this->actingAs($superadmin, 'sanctum')
            ->postJson('/api/v1/tenant-users', [
                'email' => $email,
                'role' => 'viewer',
            ])
            ->assertCreated()
            ->assertJsonPath('email_sent', true);

        Mail::assertSent(PasswordLinkMail::class);
    }

    public function test_tenant_admin_cannot_invite_users_directly(): void
    {
        Mail::fake();
        $admin = $this->makeMember('admin', 'Admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/tenant-users', [
                'email' => 'blocked-'.uniqid().'@example.test',
                'role' => 'viewer',
            ]);

        $response->assertForbidden();
        $this->assertStringContainsString('Request new user', (string) $response->json('message'));
        Mail::assertNothingSent();
    }

    public function test_admin_can_email_a_reset_link_to_a_member(): void
    {
        Mail::fake();
        $admin = $this->makeMember('admin', 'Admin');
        $member = $this->makeMember('viewer', 'Viewer');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/tenant-users/{$member->id}/password-link")
            ->assertOk()
            ->assertJsonPath('email_sent', true);

        Mail::assertSent(PasswordLinkMail::class);
    }

    public function test_manager_cannot_send_reset_links_or_set_passwords(): void
    {
        Mail::fake();
        $manager = $this->makeMember('manager', 'Manager');
        $member = $this->makeMember('viewer', 'Viewer');

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/tenant-users/{$member->id}/password-link")
            ->assertStatus(403);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/tenant-users/{$member->id}/password", ['password' => 'NewPass@1234'])
            ->assertStatus(403);

        Mail::assertNotSent(PasswordLinkMail::class);
    }

    public function test_admin_set_password_updates_the_credential_and_revokes_tokens(): void
    {
        $admin = $this->makeMember('admin', 'Admin');
        $member = $this->makeMember('viewer', 'Viewer');

        DB::table('public.personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $member->id,
            'name' => 'old-session',
            'token' => hash('sha256', Str::random(40)),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/tenant-users/{$member->id}/password", ['password' => 'NewPass@1234'])
            ->assertOk();

        $fresh = DB::table('public.users')->where('id', $member->id)->first();
        $this->assertTrue(Hash::check('NewPass@1234', $fresh->password));

        $this->assertEquals(0, DB::table('public.personal_access_tokens')
            ->where('tokenable_id', $member->id)
            ->count(), 'Existing sessions must be revoked when a password is changed.');
    }

    public function test_member_activity_feed_merges_actor_logs(): void
    {
        $admin = $this->makeMember('admin', 'Admin');
        $member = $this->makeMember('viewer', 'Viewer');

        // The harness may already mirror the user into public.users.
        $centralId = DB::table('public.users')->where('email', $member->email)->value('id')
            ?? DB::table('public.users')->insertGetId([
                'name' => $member->name,
                'email' => $member->email,
                'password' => Hash::make('irrelevant'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('posting_audit_logs')->insert([
            'document_type' => 'payment',
            'document_id' => 5,
            'action' => 'post',
            'actor_user_id' => $member->id,
            'created_at' => now(),
        ]);

        $product = \App\Models\Product::factory()->create(['name' => 'Activity Widget']);
        DB::table('product_audit_logs')->insert([
            'product_id' => $product->id,
            'user_id' => $member->id,
            'action' => 'updated',
            'field_name' => 'price',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/tenant-users/{$centralId}/activity")
            ->assertOk();

        $descriptions = collect($response->json('data.entries'))->pluck('description');

        $this->assertTrue(
            $descriptions->contains(fn ($d) => str_contains(strtolower((string) $d), 'payment #5')),
            'The activity feed must include accounting posts: '.$descriptions->implode(' | '),
        );
        $this->assertTrue(
            $descriptions->contains(fn ($d) => str_contains((string) $d, 'Activity Widget')),
            'The activity feed must include product edits: '.$descriptions->implode(' | '),
        );

        $manager = $this->makeMember('manager', 'Manager');
        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/tenant-users/{$centralId}/activity")
            ->assertStatus(403);
    }

    public function test_tenant_requests_record_last_access_for_the_member(): void
    {
        $admin = $this->makeMember('admin', 'Admin');

        DB::table('public.user_company_access')
            ->where('user_id', $admin->id)
            ->where('tenant_id', $this->tenantUuid)
            ->update(['last_accessed_at' => null]);

        // TenantTestCase excludes SetTenantFromUserPreference from the router
        // (it needs central DB context) and `withoutMiddleware` binds a
        // passthrough into the container — so construct the REAL middleware to
        // exercise the last-access touch. The production path was additionally
        // verified live against the local API.
        $request = \Illuminate\Http\Request::create('/api/v1/tenant-users', 'GET');
        $request->setUserResolver(fn () => $admin);

        $middleware = new \App\Http\Middleware\SetTenantFromUserPreference(app(\Stancl\Tenancy\Tenancy::class));
        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());

        $lastAccess = DB::table('public.user_company_access')
            ->where('user_id', $admin->id)
            ->where('tenant_id', $this->tenantUuid)
            ->value('last_accessed_at');

        $this->assertNotNull($lastAccess, 'A tenant request must record the member\'s last access.');
    }
}
