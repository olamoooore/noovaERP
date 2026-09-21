<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #125 (HIGH) — Base Customer CRUD permission enforcement.
 *
 * Before the fix, Route::apiResource('customers') + the static/dynamic customer
 * routes carried no RequirePermission middleware, while the contacts
 * sub-resource on the SAME controller was protected. Any authenticated user
 * (any role) could create/view/edit/delete any customer (incl. billing party,
 * customer codes, legal-entity assignment) and read statement/aging financial
 * data.
 *
 * Verification per the issue: real HTTP requests asserting 403 for a role
 * lacking customers.* permissions, a positive control for an authorized role,
 * and a route-inspection assertion that every /customers route is gated.
 */
class HR125CustomerPermissionEnforcementTest extends TenantTestCase
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
            ['name' => 'HR125 Tenant', 'email' => 'hr125-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
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

    // ─── Viewer: read allowed, every write denied ───────────────────

    public function test_viewer_can_read_customers(): void
    {
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->getJson('/api/v1/customers');
        $this->assertSame(200, $response->getStatusCode(), 'Viewer has customers.view: '.$response->getContent());
    }

    public function test_viewer_cannot_create_customer(): void
    {
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Should Fail', 'email' => 'fail-'.Str::random(4).'@example.com',
        ]);
        $this->assertSame(403, $response->getStatusCode(), 'Viewer must NOT create customers');
    }

    public function test_viewer_cannot_update_customer(): void
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'HR125 Customer', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->putJson("/api/v1/customers/{$customerId}", ['name' => 'Hacked']);
        $this->assertSame(403, $response->getStatusCode(), 'Viewer must NOT update customers');
    }

    public function test_viewer_cannot_delete_customer(): void
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'HR125 No Delete', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->deleteJson("/api/v1/customers/{$customerId}");
        $this->assertSame(403, $response->getStatusCode(), 'Viewer must NOT delete customers');
        $this->assertNotNull(DB::table('customers')->where('id', $customerId)->first(), 'Customer must survive a denied delete');
    }

    public function test_viewer_cannot_bulk_delete_customers(): void
    {
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->postJson('/api/v1/customers/bulk-delete', ['ids' => [1, 2, 3]]);
        $this->assertSame(403, $response->getStatusCode(), 'Viewer must NOT bulk-delete customers');
    }

    // ─── Sales: write allowed (positive control on a write route) ───

    public function test_sales_passes_create_gate(): void
    {
        $user = $this->actingAsRole('sales', 'Sales');

        $response = $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Sales Customer '.Str::random(4),
            'email' => 'sales-'.Str::random(4).'@example.com',
        ]);
        // Gate must NOT block sales (has customers.write): anything but 403.
        $this->assertNotSame(403, $response->getStatusCode(), 'Sales must pass the customers.write gate: '.$response->getContent());
    }

    // ─── Unauthenticated ────────────────────────────────────────────

    public function test_unauthenticated_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/customers');
        $this->assertSame(401, $response->getStatusCode(), 'Unauthenticated must get 401');
    }

    // ─── Route inspection: every /customers route carries a customers.* gate ──

    public function test_all_customer_routes_carry_permission_middleware(): void
    {
        $unguarded = [];
        $count = 0;
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $uri = $route->uri();
            $inScope = preg_match('#^api/v1/customers(/|$)#', $uri)
                || preg_match('#^api/v1/customer-categories#', $uri);
            if (! $inScope) {
                continue;
            }
            $count++;

            $hasGate = false;
            foreach ($route->middleware() as $mw) {
                if (str_contains((string) $mw, 'RequirePermission:customers.')) {
                    $hasGate = true;
                    break;
                }
            }
            if (! $hasGate) {
                $unguarded[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        $this->assertGreaterThan(0, $count, 'Customer routes must be registered');
        $this->assertSame(
            [],
            $unguarded,
            'These customer routes lack a customers.* permission gate: '.implode(', ', $unguarded)
        );
    }
}
