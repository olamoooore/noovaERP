<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stancl\Tenancy\Tenancy;
use Tests\TenantTestCase;

/**
 * Issues #225 / #226 / #227 (regressions from the #127 products delete guard):
 *  #225 — delete-all computed the referenced set once, before the transaction
 *  #226 — bulk-delete had no referenced-product protection at all
 *  #227 — delete-all's inline tenant resolution diverged from RequirePermission
 */
class HR225226227ProductDeleteGuardsTest extends TenantTestCase
{
    private string $tenantId;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->assertTenantSchemaReady(['products', 'invoice_lines']);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'HR225 Tenant', 'email' => 'hr225-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );

        $this->owner = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $this->owner->email],
            ['name' => $this->owner->name, 'password' => $this->owner->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $this->owner->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => 'owner', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $this->owner->assignRole('Owner');
    }

    private function product(string $name): Product
    {
        return Product::factory()->create(['name' => $name, 'sku' => 'HR225-'.uniqid(), 'track_inventory' => false]);
    }

    private function referenceByInvoiceLine(Product $product): void
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'HR225 Customer', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoiceId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-HR225-'.Str::upper(Str::random(6)),
            'customer_id' => $customerId,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => 10, 'tax_amount' => 0, 'discount_amount' => 0,
            'total' => 10, 'paid_amount' => 0, 'balance' => 10,
            'status' => 'sent', 'lifecycle_status' => 'posted',
            'version_number' => 1, 'is_active_version' => true,
            'created_by' => $this->owner->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('invoice_lines')->insert([
            'invoice_id' => $invoiceId,
            'product_id' => $product->id,
            'description' => $product->name,
            'quantity' => 1, 'unit_price' => 10, 'line_total' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // #226 — bulk-delete respects the referenced-product guard
    // ─────────────────────────────────────────────────────────────────────

    public function test_bulk_delete_skips_products_referenced_by_live_transactions(): void
    {
        $referenced = $this->product('Referenced');
        $free = $this->product('Free');
        $this->referenceByInvoiceLine($referenced);

        $response = $this->actingAs($this->owner)->postJson('/api/v1/products/bulk-delete', [
            'ids' => [$referenced->id, $free->id],
        ]);

        $response->assertStatus(200, $response->getContent());
        $this->assertSame(1, $response->json('deleted'));
        $this->assertSame([$referenced->id], $response->json('skipped_referenced'));

        $this->assertNull($referenced->fresh()->deleted_at, 'Referenced product must NOT be soft-deleted');
        $this->assertNotNull($free->fresh()->deleted_at, 'Unreferenced product must be deleted');
    }

    // ─────────────────────────────────────────────────────────────────────
    // #225 — delete-all evaluates the guard at delete time and reports actuals
    // ─────────────────────────────────────────────────────────────────────

    public function test_delete_all_skips_references_added_after_a_previous_check_and_reports_actual_count(): void
    {
        $first = $this->product('First');
        $second = $this->product('Second');

        // First purge removes both (neither referenced yet).
        $this->actingAs($this->owner)->deleteJson('/api/v1/products/delete-all')->assertStatus(200);

        $this->assertNotNull($first->fresh()->deleted_at);
        $this->assertNotNull($second->fresh()->deleted_at);

        // Create a new product and reference it; the guard must be evaluated
        // when the delete statement runs, not from a pre-computed list.
        $third = $this->product('Third');
        $this->referenceByInvoiceLine($third);

        $response = $this->actingAs($this->owner)->deleteJson('/api/v1/products/delete-all');
        $response->assertStatus(200, $response->getContent());

        $this->assertSame(0, $response->json('deleted'));
        $this->assertSame(1, $response->json('skipped_referenced'));
        $this->assertNull($third->fresh()->deleted_at);
    }

    // ─────────────────────────────────────────────────────────────────────
    // #227 — shared tenant resolution (header fallback)
    // ─────────────────────────────────────────────────────────────────────

    public function test_shared_tenant_resolution_falls_back_to_the_header(): void
    {
        // Tenancy is "initialized" but with an empty tenant id — the edge case
        // the inline copy mishandled.
        $emptyTenant = new \App\Models\Tenant(['id' => '']);
        $tenancy = app(Tenancy::class);
        $tenancy->tenant = $emptyTenant;
        $tenancy->initialized = true;
        // The tenant() helper reads the container-bound contract, so rebind it.
        app()->instance(\Stancl\Tenancy\Contracts\Tenant::class, $emptyTenant);

        $request = Request::create('/api/v1/products/delete-all', 'DELETE', [], [], [], [
            'HTTP_X_TENANT_ID' => 'header-tenant-id',
        ]);

        $this->assertSame(
            'header-tenant-id',
            \App\Http\Middleware\RequirePermission::resolveTenantId($request),
            'The shared resolver must fall back to X-Tenant-ID when the tenant id is empty'
        );
    }
}
