<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * User-creation governance (2026-09-30):
 *  - tenant users can no longer create accounts (platform-only invite);
 *  - owners/admins file a request instead and can track its status;
 *  - the platform (SuperAdmin console API) approves → account created via
 *    the shared TenantMemberService — or declines.
 */
class UserCreationGovernanceTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantUuid = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'User Governance Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    private function makePlatformAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);

        if ($user->role !== 'platform_super_admin') {
            $user->assignAdminRole('platform_super_admin', null, 'UserCreationGovernanceTest');
        }

        return $user;
    }

    private function fileRequest(User $as, string $email, string $role = 'viewer'): int
    {
        $this->actingAs($as, 'sanctum')
            ->postJson('/api/v1/tenant-users/requests', [
                'name' => 'Requested Person',
                'email' => $email,
                'role' => $role,
                'note' => 'New hire',
            ])
            ->assertCreated()
            ->assertJsonPath('request.status', 'pending');

        return (int) DB::table('public.user_creation_requests')->where('email', $email)->value('id');
    }

    /** @test */
    public function tenant_admin_cannot_create_users(): void
    {
        $admin = $this->makeMember('admin', 'Admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/tenant-users', [
                'email' => 'blocked-'.uniqid().'@example.test',
                'role' => 'viewer',
            ]);

        $response->assertForbidden();
        $this->assertStringContainsString('Request new user', (string) $response->json('message'));
    }

    /** @test */
    public function platform_superadmin_can_create_users(): void
    {
        $superadmin = $this->makePlatformAdmin();
        $email = 'gov-'.uniqid().'@example.test';

        $this->actingAs($superadmin, 'sanctum')
            ->postJson('/api/v1/tenant-users', [
                'email' => $email,
                'name' => 'Platform Created',
                'role' => 'viewer',
                'password' => 'TempPass@1234',
            ])
            ->assertCreated();

        $row = DB::table('public.users')->where('email', $email)->first();
        $this->assertNotNull($row);

        $this->assertDatabaseHas('public.user_company_access', [
            'user_id' => $row->id,
            'tenant_id' => $this->tenantUuid,
            'role' => 'viewer',
        ]);
    }

    /** @test */
    public function owner_files_a_request_tracks_it_and_duplicates_are_rejected(): void
    {
        $owner = $this->makeMember('owner', 'Owner');
        $email = 'req-'.uniqid().'@example.test';

        $this->fileRequest($owner, $email, 'sales');

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/tenant-users/requests', [
                'email' => $email,
                'role' => 'sales',
            ])
            ->assertStatus(422);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/tenant-users/requests')
            ->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.email', $email)
            ->assertJsonPath('requests.0.status', 'pending');
    }

    /** @test */
    public function member_without_users_write_cannot_request_or_list(): void
    {
        $sales = $this->makeMember('sales', 'Sales');

        $this->actingAs($sales, 'sanctum')
            ->postJson('/api/v1/tenant-users/requests', [
                'email' => 'nope-'.uniqid().'@example.test',
                'role' => 'viewer',
            ])
            ->assertForbidden();

        $this->actingAs($sales, 'sanctum')
            ->getJson('/api/v1/tenant-users/requests')
            ->assertForbidden();
    }

    /** @test */
    public function platform_approves_a_request_and_the_account_is_created(): void
    {
        $owner = $this->makeMember('owner', 'Owner');
        $email = 'appr-'.uniqid().'@example.test';
        $requestId = $this->fileRequest($owner, $email, 'manager');

        $superadmin = $this->makePlatformAdmin();

        $this->actingAs($superadmin, 'sanctum')
            ->getJson('/api/v1/admin/user-requests')
            ->assertOk()
            ->assertJsonPath('requests.0.email', $email)
            ->assertJsonPath('requests.0.requested_by_name', $owner->name);

        $this->actingAs($superadmin, 'sanctum')
            ->postJson("/api/v1/admin/user-requests/{$requestId}/approve")
            ->assertOk()
            ->assertJsonPath('request.status', 'approved')
            ->assertJsonPath('request.reviewed_by', $superadmin->id);

        $row = DB::table('public.users')->where('email', $email)->first();
        $this->assertNotNull($row, 'approval must create the account');

        $this->assertDatabaseHas('public.user_company_access', [
            'user_id' => $row->id,
            'tenant_id' => $this->tenantUuid,
            'role' => 'manager',
        ]);

        // A reviewed request cannot be approved twice.
        $this->actingAs($superadmin, 'sanctum')
            ->postJson("/api/v1/admin/user-requests/{$requestId}/approve")
            ->assertStatus(422);
    }

    /** @test */
    public function platform_declines_a_request_and_no_account_is_created(): void
    {
        $owner = $this->makeMember('owner', 'Owner');
        $email = 'decl-'.uniqid().'@example.test';
        $requestId = $this->fileRequest($owner, $email);

        $superadmin = $this->makePlatformAdmin();

        $this->actingAs($superadmin, 'sanctum')
            ->postJson("/api/v1/admin/user-requests/{$requestId}/decline", ['review_note' => 'Not required'])
            ->assertOk()
            ->assertJsonPath('request.status', 'declined');

        $this->assertDatabaseMissing('public.users', ['email' => $email]);

        $this->actingAs($superadmin, 'sanctum')
            ->postJson("/api/v1/admin/user-requests/{$requestId}/approve")
            ->assertStatus(422);
    }

    /** @test */
    public function admin_request_endpoints_require_a_platform_admin(): void
    {
        $owner = $this->makeMember('owner', 'Owner');

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/admin/user-requests')
            ->assertForbidden();
    }
}
