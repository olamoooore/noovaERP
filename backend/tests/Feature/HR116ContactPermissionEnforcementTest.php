<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #116 (CRITICAL) — Contact Master module permission enforcement.
 *
 * Before the fix, every /contacts route sat in a group whose only middleware
 * was auth:sanctum + tenant resolution: ANY authenticated user (any role)
 * could view/edit/merge/delete any contact and read sensitive module-profile
 * data (HR salary, accounting credit limits, bank details).
 *
 * Verification per the issue: real HTTP requests asserting 403 for a role
 * lacking each permission, a positive control for an authorized role, and a
 * route-inspection assertion that every /contacts route carries a contacts.*
 * gate.
 */
class HR116ContactPermissionEnforcementTest extends TenantTestCase
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
            ['name' => 'HR116 Tenant', 'email' => 'hr116-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );
        User::factory()->create();
    }

    private function actingAsRole(string $centralRole, string $spatieRole): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($spatieRole);

        return $user;
    }

    private function makeContact(): string
    {
        $id = (string) Str::uuid();
        DB::table('core_contacts')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'display_name' => 'HR116 Test Contact',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    // ─── Viewer: read allowed, every write denied ───────────────────

    public function test_viewer_can_read_contacts(): void
    {
        $this->makeContact();
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->getJson('/api/v1/contacts');
        $this->assertSame(200, $response->getStatusCode(), 'Viewer has contacts.view: '.$response->getContent());
    }

    public function test_viewer_cannot_create_contact(): void
    {
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->postJson('/api/v1/contacts', [
            'display_name' => 'Should Fail',
        ]);
        $this->assertSame(403, $response->getStatusCode(), 'Viewer must NOT create contacts');
    }

    public function test_viewer_cannot_update_contact(): void
    {
        $id = $this->makeContact();
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->putJson("/api/v1/contacts/{$id}", ['display_name' => 'Hacked']);
        $this->assertSame(403, $response->getStatusCode(), 'Viewer must NOT update contacts');
    }

    public function test_viewer_cannot_delete_contact(): void
    {
        $id = $this->makeContact();
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->deleteJson("/api/v1/contacts/{$id}");
        $this->assertSame(403, $response->getStatusCode(), 'Viewer must NOT delete contacts');
        $this->assertNotNull(DB::table('core_contacts')->where('id', $id)->first(), 'Contact must survive a denied delete');
    }

    public function test_viewer_cannot_merge_contacts(): void
    {
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->postJson('/api/v1/contacts/merge', [
            'source_ids' => [(string) Str::uuid()],
            'target_id' => (string) Str::uuid(),
        ]);
        $this->assertSame(403, $response->getStatusCode(), 'Viewer must NOT merge contacts');
    }

    public function test_viewer_cannot_import_contacts(): void
    {
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->postJson('/api/v1/contacts/import', []);
        $this->assertSame(403, $response->getStatusCode(), 'Viewer must NOT import contacts');
    }

    public function test_viewer_cannot_upsert_hr_profile(): void
    {
        $id = $this->makeContact();
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->postJson("/api/v1/contacts/{$id}/profiles/hr", [
            'salary' => 999999,
        ]);
        $this->assertSame(403, $response->getStatusCode(), 'Viewer must NOT upsert HR profile (salary data)');
    }

    // ─── Sales: cannot manage bank-account-bearing accounting profile ──

    public function test_sales_cannot_upsert_accounting_customer_profile(): void
    {
        $id = $this->makeContact();
        // Sales has contacts.profiles.manage but NOT contacts.bank_accounts.edit.
        $user = $this->actingAsRole('sales', 'Sales');

        $response = $this->actingAs($user)->postJson("/api/v1/contacts/{$id}/profiles/accounting/customer", [
            'credit_limit' => 50000,
        ]);
        $this->assertSame(403, $response->getStatusCode(), 'Sales must NOT upsert accounting customer profile (bank/credit data)');
    }

    // ─── Admin positive control ─────────────────────────────────────

    public function test_admin_passes_create_gate(): void
    {
        $user = $this->actingAsRole('admin', 'Admin');

        $response = $this->actingAs($user)->postJson('/api/v1/contacts', [
            'display_name' => 'Admin Contact '.Str::random(4),
        ]);
        // Gate must NOT block admin: anything but 403 proves the gate passed.
        $this->assertNotSame(403, $response->getStatusCode(), 'Admin must pass the contacts.create gate: '.$response->getContent());
    }

    // ─── Unauthenticated ────────────────────────────────────────────

    public function test_unauthenticated_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/contacts');
        $this->assertSame(401, $response->getStatusCode(), 'Unauthenticated must get 401');
    }

    // ─── Route inspection: every /contacts route carries a contacts.* gate ──

    public function test_all_contact_routes_carry_permission_middleware(): void
    {
        $unguarded = [];
        $count = 0;
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! preg_match('#^api/v1/contacts(/|$)#', $uri)) {
                continue;
            }
            $count++;

            $hasGate = false;
            foreach ($route->middleware() as $mw) {
                if (str_contains((string) $mw, 'RequirePermission:contacts.')) {
                    $hasGate = true;
                    break;
                }
            }
            if (! $hasGate) {
                $unguarded[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        $this->assertGreaterThan(0, $count, 'Contact routes must be registered');
        $this->assertSame(
            [],
            $unguarded,
            'These contact routes lack a contacts.* permission gate: '.implode(', ', $unguarded)
        );
    }
}
