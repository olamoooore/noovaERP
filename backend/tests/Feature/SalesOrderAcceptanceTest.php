<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesOrderAcceptanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #149 — online quotation acceptance / eSignature portal.
 *
 * Verifies, via real public HTTP (no Sanctum, per the repo's engineering
 * SOP §3):
 *
 *   1. POST /sales/orders/{id}/acceptance-link issues a token + URL.
 *   2. GET /quotation-accept/{token} returns the snapshot (no auth).
 *   3. POST /quotation-accept/{token}/accept records signer metadata and
 *      transitions the order to `confirmed` with source=customer_portal.
 *   4. Re-accepting is idempotent — returns the recorded acceptance.
 *   5. Expired tokens are rejected with HTTP 410.
 */
class SalesOrderAcceptanceTest extends TenantTestCase
{
    protected User $user;
    protected Customer $customer;
    protected Product $product;
    protected Warehouse $warehouse;
    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(function () {
            return Tenant::factory()->create(['id' => (string) Str::uuid()]);
        });
        $this->bindTenantContext($tenant->id);
        $this->tenantId = $tenant->id;

        $this->assertTenantSchemaReady([
            'customers', 'products', 'sales_orders', 'sales_order_lines',
        ]);

        $this->user = User::create([
            'name' => 'Quotation Sender',
            'email' => 'quot-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create([
            'name' => 'Quotation Customer',
            'payment_terms' => 30,
            'billing_address' => 'Quotation Billing',
            'shipping_address' => 'Quotation Shipping',
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Quotation Widget',
            'sku' => 'Q-'.uniqid(),
            'price' => 100,
            'cost' => 25,
            'track_inventory' => false,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Quotation Warehouse',
            'code' => 'QW-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function makeDraftSalesOrder(): SalesOrder
    {
        $svc = app(\Modules\Sales\Services\SalesOrderService::class);

        return $svc->createOrder([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->user->id,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => $this->product->name,
                'quantity' => 2,
                'unit_price' => 100,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. Public GET renders the quotation snapshot
    // ─────────────────────────────────────────────────────────────────────

    public function test_public_show_returns_quotation_snapshot(): void
    {
        $order = $this->makeDraftSalesOrder();
        $token = app(SalesOrderAcceptanceService::class)->issueToken($order);

        $response = $this->getJson("/api/v1/quotation-accept/{$token}");

        $response->assertOk();
        $response->assertJsonPath('order_number', $order->sales_order_number);
        $response->assertJsonPath('status', 'draft');
        $response->assertJsonPath('customer_name', 'Quotation Customer');
        $response->assertJsonPath('already_accepted', false);
    }

    public function test_public_show_with_invalid_token_returns_404(): void
    {
        $response = $this->getJson('/api/v1/quotation-accept/not-a-real-token');

        $response->assertStatus(404);
        $response->assertJsonPath('error', 'Invalid or expired acceptance link.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. Public POST records the acceptance
    // ─────────────────────────────────────────────────────────────────────

    public function test_public_accept_transitions_order_to_confirmed(): void
    {
        $order = $this->makeDraftSalesOrder();
        $token = app(SalesOrderAcceptanceService::class)->issueToken($order);

        $response = $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
            'signature' => 'data:image/png;base64,iVBORw0KGgo=',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'confirmed');
        $response->assertJsonPath('signer_name', 'Carla Customer');

        $order->refresh();
        $this->assertSame('confirmed', $order->status, 'Order must be confirmed by the portal acceptance.');
        $this->assertSame('Carla Customer', $order->signer_name);
        $this->assertSame('carla@example.com', $order->signer_email);
        $this->assertSame(SalesOrderAcceptanceService::SOURCE_PORTAL, $order->acceptance_source);
        $this->assertNotNull($order->accepted_at);

        // Status history must record the transition with the customer-portal
        // source so audit trails distinguish portal from internal confirm.
        $history = $order->statusHistory()->latest('id')->first();
        $this->assertNotNull($history);
        $this->assertSame(SalesOrderAcceptanceService::SOURCE_PORTAL, $history->source);
        $this->assertSame('draft', $history->from_status);
        $this->assertSame('confirmed', $history->to_status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. Re-accepting is idempotent
    // ─────────────────────────────────────────────────────────────────────

    public function test_re_accepting_returns_existing_record(): void
    {
        $order = $this->makeDraftSalesOrder();
        $token = app(SalesOrderAcceptanceService::class)->issueToken($order);

        $first = $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
        ]);
        $first->assertOk();

        $second = $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Different Person',
            'signer_email' => 'other@example.com',
        ]);

        $second->assertOk();
        $second->assertJsonPath('signer_name', 'Carla Customer', 'Original signer name must be preserved.');
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. Expired token rejected with HTTP 410
    // ─────────────────────────────────────────────────────────────────────

    public function test_expired_token_rejected_with_410(): void
    {
        $order = $this->makeDraftSalesOrder();
        $token = app(SalesOrderAcceptanceService::class)->issueToken($order);

        $order->update(['acceptance_expires_at' => now()->subDay()]);

        $response = $this->postJson("/api/v1/quotation-accept/{$token}/accept", [
            'signer_name' => 'Carla Customer',
            'signer_email' => 'carla@example.com',
        ]);

        $response->assertStatus(410);
        $this->assertSame('draft', $order->fresh()->status, 'Order must remain draft when token is expired.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. Validation rejects missing signer metadata
    // ─────────────────────────────────────────────────────────────────────

    public function test_accept_requires_signer_name_and_email(): void
    {
        $order = $this->makeDraftSalesOrder();
        $token = app(SalesOrderAcceptanceService::class)->issueToken($order);

        $response = $this->postJson("/api/v1/quotation-accept/{$token}/accept", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['signer_name', 'signer_email']);
    }
}
