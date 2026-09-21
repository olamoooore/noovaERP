<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #143 (BLOCKER) — Fulfillment public route crash + carrier-credential
 * exposure.
 *
 * Before the fix:
 *  - The "public" group used ->middleware(['api','tenant']) but no 'tenant'
 *    alias exists, so EVERY request to the group crashed at middleware
 *    resolution.
 *  - That same group exposed full carrier-credential CRUD
 *    (POST/PUT/DELETE/test on /fulfillment/providers) via ProviderController,
 *    duplicating AdminFulfillmentController.
 *
 * Verification per the issue:
 *  1. POST /fulfillment/providers (public) must NOT exist -> 404 (not merely
 *     reject unauthenticated callers).
 *  2. No fulfillment route may reference the broken 'tenant' alias.
 *  3. Admin credential CRUD requires fulfillment.settings (Owner/Admin).
 *  4. A non-settings role is denied credential CRUD (403).
 */
class HR143FulfillmentRouteSecurityTest extends TenantTestCase
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
            ['name' => 'HR143 Tenant', 'email' => 'hr143-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
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

    // ─── 1. Public credential-CRUD routes must be gone ──────────────
    //
    // The read routes (GET /fulfillment/providers, GET /fulfillment/providers/{id})
    // legitimately remain, so the URIs still resolve. What must be GONE is the
    // mutation verbs (POST/PUT/DELETE). Laravel returns 405 Method Not Allowed
    // when a URI exists for other verbs but not the requested one — that is the
    // precise proof the credential-CRUD endpoints were removed. The forbidden
    // outcomes are 200/201 (still exposed) and 401/403 (route exists, merely
    // gated) — neither is acceptable here.

    public function test_public_provider_create_route_is_gone(): void
    {
        $user = $this->actingAsRole('admin', 'Admin');

        $response = $this->actingAs($user)->postJson('/api/v1/fulfillment/providers', [
            'carrier' => 'dhl', 'api_key' => 'secret',
        ]);
        $this->assertNotContains($response->getStatusCode(), [200, 201, 403], 'Public credential-create must not exist or be reachable');
        $this->assertSame(405, $response->getStatusCode(), 'POST /fulfillment/providers must be Method Not Allowed (mutation verb removed)');
    }

    public function test_public_provider_update_and_delete_routes_are_gone(): void
    {
        $user = $this->actingAsRole('admin', 'Admin');

        $put = $this->actingAs($user)->putJson('/api/v1/fulfillment/providers/1', ['api_key' => 'x']);
        $this->assertSame(405, $put->getStatusCode(), 'PUT /fulfillment/providers/{id} must be Method Not Allowed (credential-update removed)');

        $del = $this->actingAs($user)->deleteJson('/api/v1/fulfillment/providers/1');
        $this->assertSame(405, $del->getStatusCode(), 'DELETE /fulfillment/providers/{id} must be Method Not Allowed (credential-delete removed)');
    }

    // ─── 2. No fulfillment route uses the broken 'tenant' alias ─────

    public function test_no_fulfillment_route_uses_broken_tenant_alias(): void
    {
        $offenders = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (! str_contains($route->uri(), 'fulfillment')) {
                continue;
            }
            foreach ($route->middleware() as $mw) {
                if ((string) $mw === 'tenant') {
                    $offenders[] = $route->uri();
                }
            }
        }
        $this->assertSame([], $offenders, 'These fulfillment routes still use the non-existent tenant alias: '.implode(', ', $offenders));
    }

    public function test_public_fulfillment_group_does_not_crash(): void
    {
        // A GET on a remaining public route must NOT 500 from middleware
        // resolution. Unauthenticated -> 401 (parent group auth), proving the
        // broken 'tenant' alias no longer crashes the group.
        $response = $this->getJson('/api/v1/fulfillment/providers');
        $this->assertNotSame(500, $response->getStatusCode(), 'Public fulfillment group must not crash at middleware resolution');
        $this->assertSame(401, $response->getStatusCode(), 'Unauthenticated read must be 401 (inherited auth), not 500');
    }

    // ─── 3/4. Admin credential CRUD requires fulfillment.settings ───

    public function test_manager_cannot_create_carrier_credential(): void
    {
        // Manager has fulfillment.view + fulfillment.write but NOT
        // fulfillment.settings (Owner/Admin only).
        $user = $this->actingAsRole('manager', 'Manager');

        $response = $this->actingAs($user)->postJson('/api/v1/admin/fulfillment/providers', [
            'carrier' => 'dhl', 'api_key' => 'secret',
        ]);
        $this->assertSame(403, $response->getStatusCode(), 'Manager must NOT manage carrier credentials (needs fulfillment.settings)');
    }

    public function test_admin_passes_credential_create_gate(): void
    {
        $user = $this->actingAsRole('admin', 'Admin');

        $response = $this->actingAs($user)->postJson('/api/v1/admin/fulfillment/providers', [
            'carrier' => 'dhl', 'api_key' => 'secret', 'name' => 'DHL',
        ]);
        // Gate must NOT block admin: anything but 403 proves the settings gate
        // passed (201 created, or 422 if validation wants more fields).
        $this->assertNotSame(403, $response->getStatusCode(), 'Admin must pass the fulfillment.settings gate: '.$response->getContent());
    }

    // ─── Route inspection: every fulfillment route carries a gate ───

    public function test_all_fulfillment_routes_carry_permission_middleware(): void
    {
        $unguarded = [];
        $count = 0;
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_contains($uri, 'fulfillment')) {
                continue;
            }
            $count++;
            $hasGate = false;
            foreach ($route->middleware() as $mw) {
                if (str_contains((string) $mw, 'RequirePermission:fulfillment.')) {
                    $hasGate = true;
                    break;
                }
            }
            if (! $hasGate) {
                $unguarded[] = implode('|', $route->methods()).' '.$uri;
            }
        }
        $this->assertGreaterThan(0, $count, 'Fulfillment routes must be registered');
        $this->assertSame([], $unguarded, 'These fulfillment routes lack a fulfillment.* gate: '.implode(', ', $unguarded));
    }

    public function test_credential_crud_routes_require_settings_permission(): void
    {
        $verbs = ['POST', 'PUT', 'DELETE'];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_contains($uri, 'admin/fulfillment/providers')) {
                continue;
            }
            if (! array_intersect($route->methods(), $verbs)) {
                continue;
            }
            // The test/toggle POSTs and create/update/delete must require settings.
            $hasSettings = false;
            foreach ($route->middleware() as $mw) {
                if (str_contains((string) $mw, 'RequirePermission:fulfillment.settings')) {
                    $hasSettings = true;
                }
            }
            $this->assertTrue(
                $hasSettings,
                "Admin credential route {$uri} [".implode('|', $route->methods()).'] must require fulfillment.settings'
            );
        }
    }
}
