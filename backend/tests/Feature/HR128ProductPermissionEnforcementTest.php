<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #128 (CRITICAL) — Product module permission enforcement.
 *
 * Before the fix, every product route sat in a group whose only middleware was
 * auth:sanctum + tenant resolution: ANY authenticated user (any role) could
 * create/edit/delete/export/import/bulk-delete the whole catalogue.
 *
 * Verification per the issue: real HTTP requests asserting 403 for a role
 * lacking each permission, plus a positive control for an authorized role and
 * a route-inspection assertion that every product route carries a products.*
 * gate.
 */
class HR128ProductPermissionEnforcementTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(RolePermissionSeeder::class);

        // Central tenant row so user_company_access FK is satisfied.
        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'HR128 Tenant', 'email' => 'hr128-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );
        // Baseline tenant user for ProductAuditLog attribution on create.
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

    private function makeProduct(string $name): Product
    {
        return Product::create([
            'name' => $name,
            'sku' => 'SKU-'.Str::random(8),
            'type' => 'product',
            'sale_price' => 10,
            'cost_price' => 5,
            'is_active' => true,
        ]);
    }

    // ─── Viewer: read allowed, every write denied ───────────────────

    public function test_viewer_can_read_products(): void
    {
        $this->makeProduct('Viewer Read');
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->getJson('/api/v1/products');
        $this->assertSame(200, $response->getStatusCode(), 'Viewer has products.view: '.$response->getContent());
    }

    public function test_viewer_cannot_create_product(): void
    {
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->postJson('/api/v1/products', [
            'name' => 'Should Fail', 'sku' => 'FAIL-1', 'type' => 'product',
            'sale_price' => 10, 'cost_price' => 5,
        ]);
        $this->assertSame(403, $response->getStatusCode(), 'Viewer must NOT create products');
    }

    public function test_viewer_cannot_update_product(): void
    {
        $product = $this->makeProduct('Viewer No Update');
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->putJson("/api/v1/products/{$product->id}", ['name' => 'Hacked']);
        $this->assertSame(403, $response->getStatusCode(), 'Viewer must NOT update products');
    }

    public function test_viewer_cannot_delete_product(): void
    {
        $product = $this->makeProduct('Viewer No Delete');
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->deleteJson("/api/v1/products/{$product->id}");
        $this->assertSame(403, $response->getStatusCode(), 'Viewer must NOT delete products');
        $this->assertNotNull(Product::find($product->id), 'Product must survive a denied delete');
    }

    public function test_viewer_cannot_import_products(): void
    {
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->postJson('/api/v1/products/import', []);
        $this->assertSame(403, $response->getStatusCode(), 'Viewer must NOT import products');
    }

    public function test_viewer_cannot_bulk_delete_products(): void
    {
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->postJson('/api/v1/products/bulk-delete', ['ids' => [1, 2, 3]]);
        $this->assertSame(403, $response->getStatusCode(), 'Viewer must NOT bulk-delete products');
    }

    public function test_viewer_cannot_create_variant(): void
    {
        $product = $this->makeProduct('Variant Parent');
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->postJson("/api/v1/products/{$product->id}/variants", [
            'sku' => 'VAR-1', 'name' => 'Variant',
        ]);
        $this->assertSame(403, $response->getStatusCode(), 'Viewer must NOT create variants');
    }

    // ─── Admin positive control: gate allows through ────────────────

    public function test_admin_passes_create_gate(): void
    {
        $user = $this->actingAsRole('admin', 'Admin');

        $response = $this->actingAs($user)->postJson('/api/v1/products', [
            'name' => 'Admin Product', 'sku' => 'ADM-'.Str::random(6), 'type' => 'product',
            'sale_price' => 10, 'cost_price' => 5,
        ]);
        // Gate must NOT block admin: expect anything but 403 (201 created, or
        // 422 if validation wants more fields — both prove the gate passed).
        $this->assertNotSame(403, $response->getStatusCode(), 'Admin must pass the products.create gate: '.$response->getContent());
    }

    // ─── Unauthenticated ────────────────────────────────────────────

    public function test_unauthenticated_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/products');
        $this->assertSame(401, $response->getStatusCode(), 'Unauthenticated must get 401');
    }

    // ─── Route inspection: every product route carries a products.* gate ──

    public function test_all_product_routes_carry_permission_middleware(): void
    {
        $unguarded = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $uri = $route->uri();
            // Only the Product-module routes in scope (exclude public storefront,
            // stock/pos/analytics which belong to other modules/concerns).
            $inScope = preg_match('#^api/v1/products(/|$)#', $uri)
                || preg_match('#^api/v1/product-(categories|units|attributes|features|custom-fields)(/|$)#', $uri)
                || preg_match('#^api/v1/product-variants(/|$)#', $uri);
            if (! $inScope) {
                continue;
            }

            $hasGate = false;
            foreach ($route->middleware() as $mw) {
                if (str_contains((string) $mw, 'RequirePermission:products.')) {
                    $hasGate = true;
                    break;
                }
            }
            if (! $hasGate) {
                $unguarded[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            'These in-scope product routes lack a products.* permission gate: '.implode(', ', $unguarded)
        );
    }
}
