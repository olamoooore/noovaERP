<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\SuperAdmin\Application\CatalogService;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Database\Seeders\ModuleFeatureSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockLevel;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\Storefront\Enums\OrderStatus;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\AccountService;
use Modules\Storefront\Services\CartService;
use Tests\TenantTestCase;

/**
 * SF-016 — Payment initiation + reconciliation (FR-PAY-01/02/08, C5, PCI
 * SAQ-A posture). HitPay is the faked-at-the-HTTP-boundary gateway here;
 * the Stripe SDK path is intentionally NOT exercised locally (disclosed —
 * sandbox E2E is the operator step, A-06/A-14).
 */
class SF016PaymentInitiationTest extends TenantTestCase
{
    private string $tenantId;

    private int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withCredentials();
        Queue::fake();
        DB::statement('SET search_path TO public');

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF016 Tenant', 'email' => 'sf016-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);

        $item = CatalogItem::where('key', 'storefront')->firstOrFail();
        TenantEntitlement::query()->create([
            'tenant_id' => $this->tenantId, 'catalog_item_id' => $item->id,
            'is_entitled' => true, 'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE, 'resolved_at' => now(),
        ]);
        app(FeatureGateService::class)->invalidate($this->tenantId);

        StorefrontSettings::current();
        $this->warehouseId = (int) Warehouse::create(['name' => 'SF016 WH', 'code' => 'S16'.Str::upper(Str::random(3)), 'is_active' => true])->id;

        PaymentGateway::create([
            'gateway_name' => 'hitpay',
            'api_key' => 'business-key-test',
            'is_active' => true,
            'is_test_mode' => true,
        ]);

        if (! User::query()->exists()) {
            User::factory()->create();
        }
    }

    /** Fake HitPay: unique id per issued intent; status from $gatewayStatus. */
    private function fakeHitpay(string $gatewayStatus = 'completed', ?array &$issued = null): void
    {
        $issued = [];
        Http::fake(function ($req) use (&$issued, $gatewayStatus) {
            if (strtoupper($req->method()) === 'GET') {
                return Http::response(['status' => $gatewayStatus, 'amount' => '10.00', 'currency' => 'USD', 'payment_type' => 'paynow'], 200);
            }

            $id = 'hr_'.Str::random(10);
            $issued[] = $id;

            return Http::response(['id' => $id, 'url' => 'https://sandbox.hitpay.biz/'.$id, 'amount' => '10.00', 'currency' => 'USD'], 200);
        });
    }

    public function test_pay_creates_gateway_intent_and_records_initiated_attempt(): void
    {
        $this->fakeHitpay(issued: $issued);

        [$orderId, $token] = $this->checkedOutOrder(12.00);

        $res = $this->postJson("/api/v1/storefront/orders/{$orderId}/pay", [
            'token' => $token,
            'gateway' => 'hitpay',
            'success_url' => '/w/shop/thanks',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.gateway_name', 'hitpay')
            ->assertJsonPath('data.transaction_id', $issued[0]);
        $this->assertSame('https://sandbox.hitpay.biz/'.$issued[0], $res->json('data.payment_url'));

        $txn = PaymentTransaction::query()->where('transaction_id', $issued[0])->sole();
        $this->assertSame('storefront_order', $txn->metadata['type']);
        $this->assertSame($orderId, (int) $txn->metadata['order_id']);
        $this->assertSame('pending', $txn->status);

        $attempt = StorefrontOrder::find($orderId)->paymentAttempts()->sole();
        $this->assertSame($issued[0], $attempt->transaction_ref);

        // No credential material in the response (PCI SAQ-A posture).
        $this->assertStringNotContainsString('business-key-test', $res->getContent());
    }

    public function test_ownership_and_state_gates_on_pay(): void
    {
        $this->fakeHitpay();

        [$orderId, $token] = $this->checkedOutOrder(9.00);

        $this->postJson("/api/v1/storefront/orders/{$orderId}/pay", ['token' => bin2hex(random_bytes(16))])
            ->assertStatus(403);
        $this->postJson("/api/v1/storefront/orders/{$orderId}/pay", [])
            ->assertStatus(403);
        $this->assertSame(0, PaymentTransaction::query()->count());

        $order = StorefrontOrder::find($orderId);
        app(\Modules\Storefront\Services\OrderService::class)->transitionOrder(
            $order, OrderStatus::CANCELLED, \Modules\Storefront\Enums\ActorType::SYSTEM,
        );
        $this->postJson("/api/v1/storefront/orders/{$orderId}/pay", ['token' => $token])
            ->assertStatus(409)->assertJsonPath('code', 'not_payable');

        [$o2, $t2] = $this->checkedOutOrder(4.00);
        $this->postJson("/api/v1/storefront/orders/{$o2}/pay", ['token' => $t2, 'gateway' => 'bitcoin'])
            ->assertStatus(422);
        $this->postJson("/api/v1/storefront/orders/{$o2}/pay", ['token' => $t2, 'gateway' => 'stripe'])
            ->assertStatus(422)->assertJsonPath('code', 'gateway_unavailable');
    }

    public function test_confirm_reconciles_server_side_then_completes_canonically(): void
    {
        $this->fakeHitpay('completed', $issued);

        [$orderId, $token] = $this->checkedOutOrder(10.00, tracked: true);

        $pay = $this->postJson("/api/v1/storefront/orders/{$orderId}/pay", ['token' => $token, 'gateway' => 'hitpay'])->assertStatus(200);

        $res = $this->postJson("/api/v1/storefront/orders/{$orderId}/confirm-payment", [
            'token' => $token, 'transaction_id' => $pay->json('data.transaction_id'),
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.completed', true)
            ->assertJsonPath('data.order_status', 'paid')
            ->assertJsonPath('data.payment_status', 'paid');

        $order = StorefrontOrder::find($orderId);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(1, StockMovement::query()->where('reference_type', 'storefront_order')->where('reference_id', $order->id)->count());
        $this->assertNotNull(PaymentTransaction::query()->where('transaction_id', $issued[0])->sole()->processed_at);

        // Idempotent second confirm → still paid, no second movement.
        $this->postJson("/api/v1/storefront/orders/{$orderId}/confirm-payment", [
            'token' => $token, 'transaction_id' => $issued[0],
        ])->assertStatus(200)->assertJsonPath('data.completed', true);
        $this->assertSame(1, StockMovement::query()->where('reference_type', 'storefront_order')->where('reference_id', $order->id)->count());
    }

    public function test_pending_gateway_status_does_not_complete(): void
    {
        $this->fakeHitpay('pending', $issued);

        [$orderId, $token] = $this->checkedOutOrder(7.00);
        $pay = $this->postJson("/api/v1/storefront/orders/{$orderId}/pay", ['token' => $token, 'gateway' => 'hitpay'])->assertStatus(200);

        $this->postJson("/api/v1/storefront/orders/{$orderId}/confirm-payment", [
            'token' => $token, 'transaction_id' => $pay->json('data.transaction_id'),
        ])->assertStatus(200)
            ->assertJsonPath('data.completed', false)
            ->assertJsonPath('data.order_status', 'pending');

        $this->assertNull(StorefrontOrder::find($orderId)->paid_at);
    }

    public function test_confirm_cannot_cross_order_or_forge_intent(): void
    {
        $this->fakeHitpay('completed', $issued);

        [$orderIdA, $tokenA] = $this->checkedOutOrder(5.00);
        [$orderIdB, $tokenB] = $this->checkedOutOrder(6.00);

        $payB = $this->postJson("/api/v1/storefront/orders/{$orderIdB}/pay", ['token' => $tokenB, 'gateway' => 'hitpay'])->assertStatus(200);
        $txnB = $payB->json('data.transaction_id');

        // B's transaction against order A: metadata binding rejects it.
        $this->postJson("/api/v1/storefront/orders/{$orderIdA}/confirm-payment", [
            'token' => $tokenA, 'transaction_id' => $txnB,
        ])->assertStatus(403)->assertJsonPath('code', 'forbidden');

        // A's token cannot confirm B.
        $this->postJson("/api/v1/storefront/orders/{$orderIdB}/confirm-payment", [
            'token' => $tokenA, 'transaction_id' => $txnB,
        ])->assertStatus(403);
    }

    public function test_session_pays_only_with_csrf_and_own_orders_only(): void
    {
        $this->fakeHitpay();

        $svc = app(AccountService::class);
        ['account' => $account, 'verification_token' => $vt] = $svc->register('payer-'.Str::random(6).'@example.test', 'Passw0rd!');
        $svc->verifyEmail($account->email, $vt);
        $auth = $svc->login($account->email, 'Passw0rd!');

        // Guest checkout FIRST: the test client's cookie jar persists within
        // a test, so the guest flow must run before session cookies exist.
        [$orderId] = $this->checkedOutOrder(3.00);

        $this->withUnencryptedCookie(AccountService::SESSION_COOKIE, $auth['session_token'])
            ->postJson("/api/v1/storefront/orders/{$orderId}/pay", [])
            ->assertStatus(419);

        $this->withUnencryptedCookies([
            AccountService::SESSION_COOKIE => $auth['session_token'],
            AccountService::CSRF_COOKIE => $auth['csrf_token'],
        ])->withHeaders(['X-CSRF-Token' => $auth['csrf_token']])
            ->postJson("/api/v1/storefront/orders/{$orderId}/pay", [])
            ->assertStatus(403);

        $order = StorefrontOrder::find($orderId);
        $order->update(['account_id' => $account->id]);
        $this->withUnencryptedCookies([
            AccountService::SESSION_COOKIE => $auth['session_token'],
            AccountService::CSRF_COOKIE => $auth['csrf_token'],
        ])->withHeaders(['X-CSRF-Token' => $auth['csrf_token']])
            ->postJson("/api/v1/storefront/orders/{$orderId}/pay", ['gateway' => 'hitpay'])
            ->assertStatus(200);
    }

    public function test_payment_methods_endpoint(): void
    {
        $res = $this->getJson('/api/v1/storefront/payment-methods');
        $res->assertStatus(200);
        $this->assertTrue(collect($res->json('data'))->pluck('name')->contains('hitpay'));
        $this->assertStringNotContainsString('business-key-test', $res->getContent());
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /** @return array{0:int,1:string} */
    private function checkedOutOrder(float $price, bool $tracked = false): array
    {
        $product = Product::factory()->create([
            'name' => 'S16 Product '.Str::random(4),
            'slug' => 's16-'.Str::random(6),
            'show_on_website' => true, 'is_active' => true, 'status' => 'active',
            'track_inventory' => $tracked,
            'stock_quantity' => $tracked ? 10 : 100,
            'price' => $price, 'tax_rate' => 0,
            'sku' => 'S16-'.Str::upper(Str::random(6)),
        ]);

        if ($tracked) {
            StockLevel::create(['product_id' => $product->id, 'warehouse_id' => $this->warehouseId, 'quantity' => 10, 'reserved_quantity' => 0]);
        }

        $token = bin2hex(random_bytes(CartService::TOKEN_BYTES));
        $cart = StorefrontCart::create(['guest_token_hash' => hash('sha256', $token), 'status' => 'active']);
        app(CartService::class)->addOrUpdateItem($cart, (int) $product->id, null, 1);

        $key = 'idem-'.Str::random(24);
        $res = $this->withUnencryptedCookie(CartService::COOKIE, $token)->postJson('/api/v1/storefront/checkout', [
            'email' => 'buyer-'.Str::random(6).'@example.test',
            'idempotency_key' => $key,
            'shipping_address' => ['name' => 'P', 'address1' => 'Q', 'city' => 'R', 'postal_code' => 'S', 'country_code' => 'US'],
        ]);
        $res->assertStatus(201);

        $order = StorefrontOrder::query()->where('idempotency_key', $key)->sole();

        return [(int) $order->id, $res->json('data.confirmation_token')];
    }
}
