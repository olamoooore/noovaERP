<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #172 (CRITICAL) — tenant.data.suspended flag is set by the SuperAdmin
 * console but never consulted at the tenant-resolution layer, so a "suspended"
 * tenant continues to have full unrestricted access.
 *
 * Fix: SetTenantFromUserPreference now blocks non-super-admin requests with
 * 403 once the resolved tenant carries `data->suspended = true`. Super-admin
 * bypasses (they need to manage the suspended tenant via the SuperAdmin
 * console and the X-Tenant-ID impersonation path).
 *
 * These tests assert the suspension check fires across the tenant-resolution
 * paths (default tenant, requested tenant via X-Tenant-ID) and that the
 * super-admin bypass works.
 */
class HR172TenantSuspendedEnforcementTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'HR172 Tenant', 'email' => 'hr172-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );

        // The local test public.users schema may lack is_super_admin (the
        // production server has it). Add it conditionally so the super-admin
        // bypass test can mark a user as a super-admin.
        if (! DB::selectOne(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema='public' AND table_name='users' AND column_name='is_super_admin'"
        )) {
            DB::statement('ALTER TABLE public.users ADD COLUMN is_super_admin BOOLEAN NOT NULL DEFAULT false');
        }
    }

    private function actingAsRole(string $centralRole, string $spatieRole, bool $isSuperAdmin = false): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            [
                'name' => $user->name,
                'password' => $user->password,
                'is_super_admin' => $isSuperAdmin,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        // Clear any residual user_company_access rows for this user from
        // previous tests — otherwise the middleware's `where('is_default',
        // true)->first()` could resolve to an older, unsuspended tenant.
        DB::table('public.user_company_access')->where('user_id', $centralId)->delete();
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($spatieRole);

        return $user;
    }

    private function suspendTenant(): void
    {
        // Write the suspended flag to BOTH the Eloquent model (the
        // production TenantOperationService::suspend path) AND the raw
        // public.tenants.data json column. Some stancl/tenancy configurations
        // store tenant.data via internal keys rather than the raw json column,
        // and the middleware reads both paths defence-in-depth — the test must
        // populate both so the check fires regardless of storage path.
        $tenant = Tenant::findOrFail($this->tenantId);
        $data = $tenant->data ?? [];
        $tenant->update(['data' => array_merge($data, ['suspended' => true])]);

        $raw = DB::table('public.tenants')->where('id', $this->tenantId)->value('data');
        $decoded = is_string($raw) && $raw !== '' && $raw !== 'null' ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);
        $decoded['suspended'] = true;
        DB::table('public.tenants')->where('id', $this->tenantId)->update([
            'data' => json_encode($decoded),
        ]);

        // Refresh the Tenancy cache so the request's middleware (which runs
        // in the same process under TenantTestCase) sees the suspended flag
        // regardless of whether it reads via the Eloquent accessor or the
        // raw json column.
        app(\Stancl\Tenancy\Tenancy::class)->initialize(Tenant::find($this->tenantId));
    }

    // ─── 1. Non-super-admin on a suspended tenant -> 403 ───────────

    public function test_non_super_admin_on_suspended_tenant_is_blocked(): void
    {
        $user = $this->actingAsRole('accountant', 'Accountant');
        $this->suspendTenant();

        $response = $this->actingAs($user)->getJson('/api/v1/accounting/customers');

        $this->assertSame(403, $response->getStatusCode(), 'Suspended tenant must block non-super-admin: '.$response->getContent());
        $this->assertStringContainsString('Tenant is suspended', $response->json('message'));
    }

    // ─── 2. Super-admin bypasses the suspension check ──────────────

    public function test_super_admin_bypasses_suspension_check(): void
    {
        $user = $this->actingAsRole('owner', 'Owner', isSuperAdmin: true);
        $this->suspendTenant();

        // Super-admin using X-Tenant-ID impersonation must NOT be blocked by
        // the suspension check — they need to manage the suspended tenant.
        $response = $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => $this->tenantId])
            ->getJson('/api/v1/accounting/customers');

        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            'Super-admin must bypass the suspension check: '.$response->getContent()
        );
    }

    // ─── 3. Non-super-admin on a non-suspended tenant is NOT blocked ─

    public function test_non_super_admin_on_active_tenant_is_not_blocked_by_suspension_check(): void
    {
        $user = $this->actingAsRole('accountant', 'Accountant');
        // No suspension flag set.

        $response = $this->actingAs($user)->getJson('/api/v1/accounting/customers');

        // Must NOT be the suspension 403 — the downstream route is customers.view
        // which the Accountant Spatie role does have. So this should be 200
        // (or at least not 403).
        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            'Active tenant must not block non-super-admin: '.$response->getContent()
        );
    }

    // ─── 4. Suspending then un-suspending restores access ────────────

    public function test_un_suspending_restores_access(): void
    {
        $user = $this->actingAsRole('accountant', 'Accountant');
        $this->suspendTenant();

        $blocked = $this->actingAs($user)->getJson('/api/v1/accounting/customers');
        $this->assertSame(403, $blocked->getStatusCode(), 'Suspended must block');

        // Unsuspend by clearing the flag.
        $tenant = Tenant::findOrFail($this->tenantId);
        $tenant->update(['data' => ($tenant->data ?? [])]); // keep the row, drop suspended

        $allowed = $this->actingAs($user)->getJson('/api/v1/accounting/customers');
        $this->assertNotSame(
            403,
            $allowed->getStatusCode(),
            'Un-suspended tenant must restore access: '.$allowed->getContent()
        );
    }
}
