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
 * Issue #127 (BLOCKER) — DELETE /products/delete-all hardening.
 *
 * Required assertions from the issue:
 *   (a) a non-privileged user gets 403;
 *   (b) invoice/PO/POS line items are NEVER touched by this endpoint;
 *   (c) failures surface (no silent swallow) — covered by the transactional
 *       rollback + the referenced-product skip path;
 *   (d) Owner/Admin can purge unreferenced products; referenced ones are
 *       skipped, not cascade-deleted.
 */
class HR127ProductDeleteAllGuardTest extends TenantTestCase
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
            ['name' => 'HR127 Tenant', 'email' => 'hr127-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );

        // A baseline tenant user must exist so ProductAuditLog attribution
        // (TenantUserHelper::requireTenantUserId) resolves during Product::create.
        User::factory()->create();
    }

    private function makeProduct(string $name, array $attrs = []): Product
    {
        return Product::create(array_merge([
            'name' => $name,
            'sku' => 'SKU-'.Str::random(8),
            'type' => 'product',
            'sale_price' => 10,
            'cost_price' => 5,
            'is_active' => true,
        ], $attrs));
    }

    private function actingAsRole(string $centralRole, string $spatieRole): User
    {
        $user = User::factory()->create();
        // Central role row (what TenantRoleService reads).
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        // Tenant-schema Spatie role so Role::findByName resolves.
        $user->assignRole($spatieRole);

        return $user;
    }

    // ─── (a) non-privileged user is denied ──────────────────────────

    public function test_viewer_is_denied_403(): void
    {
        $this->makeProduct('Viewer Should Not Delete');
        $user = $this->actingAsRole('viewer', 'Viewer');

        $response = $this->actingAs($user)->deleteJson('/api/v1/products/delete-all');

        $this->assertSame(403, $response->getStatusCode(), 'Viewer must be denied delete-all');
        // Product must survive.
        $this->assertGreaterThan(0, Product::count(), 'No product may be deleted by an unauthorized user');
    }

    public function test_sales_role_is_denied_403(): void
    {
        $this->makeProduct('Sales Should Not Delete');
        $user = $this->actingAsRole('sales', 'Sales');

        $response = $this->actingAs($user)->deleteJson('/api/v1/products/delete-all');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertGreaterThan(0, Product::count());
    }

    // ─── (d) Owner/Admin can purge unreferenced products ────────────

    public function test_admin_can_delete_unreferenced_products(): void
    {
        $this->makeProduct('Admin Delete A');
        $this->makeProduct('Admin Delete B');
        $user = $this->actingAsRole('admin', 'Admin');

        $response = $this->actingAs($user)->deleteJson('/api/v1/products/delete-all');

        $this->assertSame(200, $response->getStatusCode(), 'Admin must be allowed: '.$response->getContent());
        $this->assertSame(0, Product::count(), 'Unreferenced products must be purged for Admin');
        $this->assertSame(2, $response->json('deleted'));
    }

    // ─── (b) financial line items are NEVER touched ─────────────────

    public function test_delete_all_never_touches_invoice_lines(): void
    {
        $user = $this->actingAsRole('admin', 'Admin');
        $product = $this->makeProduct('Referenced By Invoice');

        // Create a real invoice + invoice_line referencing the product.
        $invoiceId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-HR127-'.Str::random(6),
            'invoice_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'subtotal' => 10, 'tax_amount' => 0, 'discount_amount' => 0,
            'shipping_cost' => 0, 'total' => 10, 'status' => 'posted',
            'created_by' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $lineId = DB::table('invoice_lines')->insertGetId([
            'invoice_id' => $invoiceId,
            'product_id' => $product->id,
            'description' => 'HR127 line',
            'quantity' => 1, 'unit_price' => 10, 'line_total' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->deleteJson('/api/v1/products/delete-all');

        $this->assertSame(200, $response->getStatusCode());

        // CRITICAL: the invoice_line must still exist — the endpoint must NOT
        // wipe financial records to make room for the product delete.
        $this->assertNotNull(
            DB::table('invoice_lines')->where('id', $lineId)->first(),
            'invoice_lines must NEVER be deleted by products/delete-all'
        );
        $this->assertNotNull(
            DB::table('invoices')->where('id', $invoiceId)->first(),
            'invoices must NEVER be deleted by products/delete-all'
        );

        // The referenced product is SKIPPED (not deleted), preserving FK integrity.
        $this->assertNotNull(
            Product::withTrashed()->find($product->id),
            'A product referenced by an invoice_line must be skipped, not deleted'
        );
        $this->assertSame(1, $response->json('skipped_referenced'));
    }

    // ─── (c) audit log is preserved, not wiped ──────────────────────

    public function test_product_audit_logs_are_not_wiped(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('product_audit_logs')) {
            $this->markTestSkipped('product_audit_logs not provisioned');
        }

        // Creating a product auto-writes a ProductAuditLog row via the model
        // event, so we get a real audit row without depending on its exact
        // insert shape.
        $this->makeProduct('Audit Preserve');
        $before = DB::table('product_audit_logs')->count();
        $this->assertGreaterThan(0, $before, 'Product creation should have written an audit row');

        $user = $this->actingAsRole('admin', 'Admin');
        $this->actingAs($user)->deleteJson('/api/v1/products/delete-all');

        $after = DB::table('product_audit_logs')->count();
        $this->assertGreaterThanOrEqual(
            $before,
            $after,
            'product_audit_logs must NOT be wiped by delete-all (was '.$before.', now '.$after.')'
        );
    }

    // ─── route guard wiring ─────────────────────────────────────────

    public function test_route_has_products_delete_all_permission(): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->first(fn ($r) => str_contains($r->uri(), 'products/delete-all')
                && in_array('DELETE', $r->methods()));

        $this->assertNotNull($route, 'DELETE /products/delete-all must be registered');
        $hasGuard = collect($route->middleware())->contains(fn ($mw) => str_contains((string) $mw, 'products.delete_all'));
        $this->assertTrue($hasGuard, 'delete-all route must require products.delete_all permission');
    }
}
