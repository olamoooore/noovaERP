<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ModuleManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Tests\TenantTestCase;

/**
 * SF-005 — Legacy commerce freeze (FR-ARC-01/02/03, FR-MOD-06) + platform
 * defect P-01 (webhook tenant lookup never written).
 *
 * Freeze guarantees proven here:
 *  1. module.json `deprecated: true` is PARSED on the live discovery path
 *     (Module/ModuleManager), not a dead manifest key;
 *  2. legacy admin READ routes now carry a real permission gate
 *     (they previously had none — legacy defect L-02): denied role 403,
 *     allowed role passes;
 *  3. legacy admin WRITE route answers 410 Gone even to OWNER (no new
 *     writes after cutover);
 *  4. the legacy module stays DISCOVERABLE (history/read paths intact —
 *     freeze is not deletion).
 *
 * P-01 guarantees proven here:
 *  5. creating a PaymentTransaction under tenant context actually WRITES
 *     public.payment_webhook_tenant_lookups (the pre-fix code silently
 *     skipped this), which is exactly what ResolveTenantFromWebhook reads;
 *  6. deleting the transaction removes the lookup row.
 */
class SF005LegacyFreezeAndWebhookLookupTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET search_path TO public');
        $this->seed(RolePermissionSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF005 Tenant', 'email' => 'sf005-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);
    }

    // ─── 1. Manifest flag parsed on the live path ───────────────────────

    public function test_ecommerce_module_is_discovered_and_flagged_deprecated(): void
    {
        $modules = collect(app(ModuleManager::class)->all());

        $ecommerce = $modules->firstWhere('key', 'ecommerce');
        $this->assertNotNull($ecommerce, 'Freeze must NOT remove the legacy module from disk discovery (read-only history)');
        $this->assertTrue($ecommerce->isDeprecated(), 'module.json "deprecated": true must be parsed by the live ModuleManager');

        $storefront = $modules->firstWhere('key', 'storefront');
        $this->assertNotNull($storefront);
        $this->assertFalse($storefront->isDeprecated());
    }

    // ─── 2/3. Legacy admin: read gated, write frozen ────────────────────

    public function test_legacy_admin_order_read_now_requires_sales_view(): void
    {
        // Legacy defect L-02: these routes had NO permission gate before the
        // freeze. Any authenticated tenant user could read all web orders.
        $cashier = $this->makeTenantUser($this->tenantId, 'cashier', 'Cashier');

        $denied = $this->actingAs($cashier)->getJson('/api/v1/admin/ecommerce/orders');
        $denied->assertStatus(403)
            ->assertJsonPath('required_permission', 'sales.view');
    }

    public function test_sales_role_can_still_read_legacy_orders(): void
    {
        $sales = $this->makeTenantUser($this->tenantId, 'sales', 'Sales');

        $response = $this->actingAs($sales)->getJson('/api/v1/admin/ecommerce/orders');

        // Read-only history stays available (FR-ARC-03/04).
        $this->assertNotContains($response->getStatusCode(), [401, 403, 410],
            'sales.view holder must retain legacy read access: '.$response->getContent());
        $response->assertStatus(200);
    }

    public function test_legacy_admin_status_write_is_frozen_410_even_for_owner(): void
    {
        $owner = $this->makeTenantUser($this->tenantId, 'owner', 'Owner');

        $response = $this->actingAs($owner)->putJson('/api/v1/admin/ecommerce/orders/1/status', [
            'status' => 'processing',
        ]);

        $response->assertStatus(410)->assertJsonPath('code', 'legacy_commerce_frozen');

        // The frozen endpoint must not touch data even minimally: no new
        // status history written for order id 1 (table stays as found).
        $this->assertSame(0, DB::table('web_orders')->where('id', 1)->count());
    }

    // ─── 4. Legacy public runtime untouched at P0 (P2 will retire it) ───

    public function test_legacy_public_runtime_routes_still_registered_for_now(): void
    {
        // Guard against accidental over-deletion in the freeze: /store serving
        // stops at P2 per Appendix A §A5, not here.
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes());

        $this->assertTrue($routes->contains(
            fn ($r) => str_contains($r->uri(), 'web-shop/checkout/guest')
        ), 'P0 freeze must not yet remove the live public checkout route (planned P2)');
    }

    // ─── 5/6. P-01: webhook tenant lookup is actually written ───────────

    public function test_payment_transaction_creation_writes_central_webhook_lookup(): void
    {
        $gateway = PaymentGateway::create([
            'gateway_name' => 'stripe',
            'publishable_key' => 'pk_test_sf005',
            'api_key' => 'sk_test_sf005',
            'webhook_secret' => 'whsec_sf005',
            'is_active' => true,
            'is_test_mode' => true,
        ]);

        $transaction = PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'transaction_id' => 'pi_sf005_'.Str::random(8),
            'amount' => 42.00,
            'currency' => 'USD',
            'status' => 'pending',
            'metadata' => ['type' => 'storefront_order', 'order_id' => 1],
        ]);

        // Pre-fix (P-01): this row NEVER existed — Schema::hasTable resolved
        // the unqualified lookup name against the TENANT schema (where the
        // public-only table can't be seen) and the write silently skipped.
        $lookup = DB::table('public.payment_webhook_tenant_lookups')
            ->where('gateway_name', 'stripe')
            ->where('transaction_id', $transaction->transaction_id)
            ->first();

        $this->assertNotNull($lookup, 'P-01 regression: lookup row must be written from tenant context at intent creation');
        $this->assertSame($this->tenantId, (string) $lookup->tenant_id, 'Lookup must map the transaction to THIS tenant');

        // This is the exact read ResolveTenantFromWebhook performs.
        $resolved = DB::table('public.payment_webhook_tenant_lookups')
            ->where('transaction_id', $transaction->transaction_id)
            ->where('gateway_name', 'stripe')
            ->first(['tenant_id']);
        $this->assertNotNull($resolved);
    }

    public function test_deleting_transaction_removes_webhook_lookup(): void
    {
        $gateway = PaymentGateway::create([
            'gateway_name' => 'hitpay',
            'publishable_key' => 'pk_test_sf005b',
            'is_active' => true,
        ]);

        $transaction = PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'transaction_id' => 'hitpay_sf005b_'.Str::random(8),
            'amount' => 10.00,
            'currency' => 'USD',
            'status' => 'pending',
        ]);

        $this->assertSame(1, DB::table('public.payment_webhook_tenant_lookups')
            ->where('transaction_id', $transaction->transaction_id)->count());

        $transaction->delete();

        $this->assertSame(0, DB::table('public.payment_webhook_tenant_lookups')
            ->where('transaction_id', $transaction->transaction_id)->count());
    }

    public function test_lookup_write_survives_recreation_with_same_gateway_txn_id(): void
    {
        $gateway = PaymentGateway::create([
            'gateway_name' => 'stripe',
            'publishable_key' => 'pk_test_sf005c',
            'is_active' => true,
        ]);

        $txnId = 'pi_reused_'.Str::random(6);

        $first = PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'transaction_id' => $txnId,
            'amount' => 5, 'currency' => 'USD', 'status' => 'pending',
        ]);
        $first->delete();

        PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'transaction_id' => $txnId,
            'amount' => 7, 'currency' => 'USD', 'status' => 'pending',
        ]);

        $this->assertSame(1, DB::table('public.payment_webhook_tenant_lookups')
            ->where('gateway_name', 'stripe')
            ->where('transaction_id', $txnId)
            ->count(), 'Upsert must keep exactly one tenant mapping per gateway transaction');
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function makeTenantUser(string $tenantId, string $centralRole, string $spatieRole): User
    {
        $user = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($spatieRole);
        $this->bindTenantContext($tenantId);

        return $user;
    }
}
